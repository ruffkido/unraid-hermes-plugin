# Hermes Unraid Plugin

Install [Hermes Agent](https://hermes-agent.nousresearch.com/) and [Hermes WebUI](https://github.com/nesquena/hermes-webui) directly on Unraid.

## What This Plugin Installs

| Component | Location | Survives Reboot? |
|-----------|----------|------------------|
| Python 3.9 | `/usr/bin/python3` | No (reinstalled from `/boot/extra/`) |
| Hermes Agent | `/boot/config/plugins/hermes/hermes-agent/` | **Yes** (flash) |
| Hermes WebUI | `/boot/config/plugins/hermes-webui/` | **Yes** (flash) |
| Hermes Config | `/boot/config/plugins/hermes/.hermes/` | **Yes** (flash) |
| Service Scripts | `/etc/rc.d/rc.hermes-*` | No (reinstalled on boot) |

## Features

- **Hermes WebUI** — Web interface on port 9000
- **Hermes Gateway** — Telegram, Discord, Slack, Signal integration
- **Persistent config** — All settings stored on flash drive
- **Auto-start** — Services start on array startup

## Installation

This is a private plugin. Install manually:

### Step 1: Copy Plugin Files to Unraid

**Option A: Git clone (recommended)**
```bash
# SSH into Unraid
ssh root@tower

# Clone the plugin repo to flash drive
git clone https://github.com/ruffkido/unraid-hermes-plugin.git /boot/config/plugins/hermes
```

**Option B: Manual copy**
1. Download this repo as ZIP
2. Upload to Unraid via SMB/SCP
3. Extract to `/boot/config/plugins/hermes/`

### Step 2: Install the Plugin

```bash
# SSH into Unraid
cd /boot/config/plugins/hermes

# Install the plugin
installpkg hermes.plg
```

Or use the web UI:
1. Go to **Plugins** → **Install Plugin**
2. Choose **Install from file**
3. Select `/boot/config/plugins/hermes/hermes.plg`

### Step 3: Configure Hermes

After installation, configure your provider:

```bash
# Run setup wizard
hermes setup

# Or configure model/provider directly
hermes model
```

Then access the WebUI at `http://tower:9000`

## Configuration

### Enable Messaging Gateway (Optional)

Edit `/boot/config/plugins/hermes/hermes.cfg`:

```bash
WEBUI_ENABLED="yes"
GATEWAY_ENABLED="yes"  # Change to yes
WEBUI_PORT="9000"
```

Then restart gateway and configure platforms:

```bash
/etc/rc.d/rc.hermes-gateway restart
hermes gateway setup
```

### WebUI Password

Edit `/boot/config/plugins/hermes-webui/.env`:

```bash
HERMES_WEBUI_PASSWORD=your-secure-password
```

Then restart: `/etc/rc.d/rc.hermes-webui restart`

## Service Management

```bash
# WebUI
/etc/rc.d/rc.hermes-webui start
/etc/rc.d/rc.hermes-webui stop
/etc/rc.d/rc.hermes-webui status

# Gateway
/etc/rc.d/rc.hermes-gateway start
/etc/rc.d/rc.hermes-gateway stop
/etc/rc.d/rc.hermes-gateway status
```

## Files and Directories

```
/boot/config/plugins/
├── hermes/
│   ├── hermes.plg          # Plugin definition
│   ├── hermes.cfg          # Plugin settings
│   ├── hermes-agent/       # Hermes Agent git repo
│   ├── rc.hermes-webui     # WebUI service script
│   ├── rc.hermes-gateway   # Gateway service script
│   └── .hermes/            # Hermes config
│       ├── config.yaml     # Main config
│       ├── .env            # API keys
│       ├── sessions/       # Chat history
│       └── skills/         # Learned skills
│
└── hermes-webui/           # WebUI git repo
    ├── .env                # WebUI settings
    └── venv/               # Python deps
```

## Environment Variables

Set in `/boot/config/plugins/hermes-webui/.env`:

| Variable | Description | Default |
|----------|-------------|---------|
| `HERMES_WEBUI_HOST` | Bind address | `0.0.0.0` |
| `HERMES_WEBUI_PORT` | Port | `9000` |
| `HERMES_WEBUI_PASSWORD` | HTTP Basic Auth | *(unset)* |
| `HERMES_WEBUI_AGENT_DIR` | Agent path | `/boot/config/plugins/hermes/hermes-agent` |
| `HERMES_HOME` | Config dir | `/boot/config/plugins/hermes/.hermes` |

## Uninstalling

```bash
# Stop services
/etc/rc.d/rc.hermes-webui stop
/etc/rc.d/rc.hermes-gateway stop

# Remove plugin files
rm -rf /boot/config/plugins/hermes
rm -rf /boot/config/plugins/hermes-webui

# Reboot to clean up /usr/local/bin/hermes and /etc/rc.d/ scripts
```

## Troubleshooting

### WebUI not starting

```bash
# Check logs
tail -f /boot/config/plugins/hermes/.hermes/webui.log

# Reinstall deps
cd /boot/config/plugins/hermes-webui
source venv/bin/activate
pip install -r requirements.txt
```

### Gateway not connecting

```bash
hermes gateway status
hermes gateway setup
```

### Python not found

```bash
upgradepkg --install-new /boot/extra/python3-*.txz
```

## Credits

- [Hermes Agent](https://github.com/NousResearch/hermes-agent) by NousResearch
- [Hermes WebUI](https://github.com/nesquena/hermes-webui) by nesquena

## License

MIT
