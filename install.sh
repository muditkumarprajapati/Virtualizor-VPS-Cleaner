#!/usr/bin/env bash
# ==============================================================================
# Virtualizor VPS Cleanup Manager - Automated Installer
# https://github.com/muditkumarprajapati/virtualizor-vps-cleaner
# ==============================================================================

set -e

# ANSI Color Codes
BOLD="\033[1m"
GREEN="\033[92m"
CYAN="\033[96m"
YELLOW="\033[93m"
RED="\033[91m"
RESET="\033[0m"

echo -e "\n${CYAN}${BOLD}╔══════════════════════════════════════════════════════════════════════════════╗${RESET}"
echo -e "${CYAN}${BOLD}║                       VIRTUALIZOR VPS CLEANUP MANAGER                        ║${RESET}"
echo -e "${CYAN}${BOLD}║                        Automated System Installer                            ║${RESET}"
echo -e "${CYAN}${BOLD}╚══════════════════════════════════════════════════════════════════════════════╝${RESET}\n"

# 1. Root permission check
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}${BOLD}Error:${RESET} This installer must be run as root."
    exit 1
fi

# 2. Detect Linux Distribution
OS_NAME="unknown"
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS_NAME=$ID
elif [ -f /etc/redhat-release ]; then
    OS_NAME="rhel"
elif [ -f /etc/debian_version ]; then
    OS_NAME="debian"
fi

echo -e "${CYAN}→ Detected Operating System:${RESET} ${BOLD}${NAME:-$OS_NAME} ${VERSION_ID:-}${RESET}"

# 3. Check and install git if missing
if ! command -v git &>/dev/null; then
    echo -e "${YELLOW}→ Git is not installed. Installing git for ${OS_NAME}...${RESET}"
    case "$OS_NAME" in
        almalinux|rocky|centos|rhel|fedora|cloudlinux)
            if command -v dnf &>/dev/null; then
                dnf install -y git curl
            else
                yum install -y git curl
            fi
            ;;
        ubuntu|debian)
            apt-get update -qq
            DEBIAN_FRONTEND=noninteractive apt-get install -y -qq git curl
            ;;
        opensuse*|sles)
            zypper install -y git curl
            ;;
        arch|manjaro)
            pacman -Sy --noconfirm git curl
            ;;
        *)
            echo -e "${YELLOW}Warning: Unknown package manager. Attempting yum/apt fallback...${RESET}"
            if command -v dnf &>/dev/null; then
                dnf install -y git curl
            elif command -v yum &>/dev/null; then
                yum install -y git curl
            elif command -v apt-get &>/dev/null; then
                apt-get update -qq && apt-get install -y git curl
            else
                echo -e "${RED}Could not install git automatically. Please install git manually and re-run.${RESET}"
                exit 1
            fi
            ;;
    esac
else
    echo -e "${GREEN}✓ Git is already installed.${RESET}"
fi

# 4. Target Installation Directory
INSTALL_DIR="/opt/virtualizor-vps-cleaner"
REPO_URL="https://github.com/muditkumarprajapati/virtualizor-vps-cleaner.git"

if [ -d "$INSTALL_DIR/.git" ]; then
    echo -e "${CYAN}→ Existing installation found in ${INSTALL_DIR}. Updating via git pull...${RESET}"
    git -C "$INSTALL_DIR" pull --quiet
else
    echo -e "${CYAN}→ Cloning repository into ${INSTALL_DIR}...${RESET}"
    rm -rf "$INSTALL_DIR"
    git clone --quiet "$REPO_URL" "$INSTALL_DIR"
fi

chmod +x "$INSTALL_DIR/virtualizor-vps-cleaner.php"

# 5. Detect PHP environment (Prefer Virtualizor EMPS PHP if present)
PHP_BIN=""
if [ -x /usr/local/emps/bin/php ]; then
    PHP_BIN="/usr/local/emps/bin/php"
    echo -e "${GREEN}✓ Found Virtualizor EMPS PHP at: ${PHP_BIN}${RESET}"
elif command -v php &>/dev/null; then
    PHP_BIN="$(command -v php)"
    echo -e "${GREEN}✓ Found System PHP at: ${PHP_BIN}${RESET}"
else
    echo -e "${RED}${BOLD}Error:${RESET} No PHP binary found. Please install PHP or ensure Virtualizor is installed."
    exit 1
fi

# 6. Create wrapper script in /usr/local/bin for global terminal execution
WRAPPER_BIN="/usr/local/bin/virtualizor-cleaner"
cat << 'EOF' > "$WRAPPER_BIN"
#!/usr/bin/env bash
PHP_EXEC=""
if [ -x /usr/local/emps/bin/php ]; then
    PHP_EXEC="/usr/local/emps/bin/php"
elif command -v php &>/dev/null; then
    PHP_EXEC="$(command -v php)"
else
    echo "Error: No PHP binary located." >&2
    exit 1
fi

exec "$PHP_EXEC" "/opt/virtualizor-vps-cleaner/virtualizor-vps-cleaner.php" "$@"
EOF

chmod +x "$WRAPPER_BIN"

echo -e "\n${GREEN}${BOLD}==================================================================${RESET}"
echo -e "${GREEN}${BOLD}   Virtualizor VPS Cleanup Manager Installed Successfully!        ${RESET}"
echo -e "${GREEN}${BOLD}==================================================================${RESET}\n"

echo -e "You can now run the cleanup manager from any directory with:"
echo -e "  ${CYAN}${BOLD}virtualizor-cleaner${RESET}\n"
echo -e "Or execute directly via:"
echo -e "  ${BOLD}${PHP_BIN} ${INSTALL_DIR}/virtualizor-vps-cleaner.php${RESET}\n"
