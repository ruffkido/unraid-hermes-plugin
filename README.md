# Hermes Unraid Plugin

Install [Hermes Agent](https://github.com/NousResearch/hermes-agent) and [Hermes WebUI](https://github.com/nesquena/hermes-webui) on Unraid as a native plugin. Adds a **Settings → Hermes** page for service management and inline editing of `config.yaml` / `.env`.

## Quick start

End-to-end, ~5 minutes:

```bash
# 1. SSH into your Unraid server
ssh root@<your-unraid-host>

# 2. Clone the plugin repo onto the flash drive
git clone https://github.com/ruffkido/unraid-hermes-plugin.git /boot/config/plugins/hermes

# 3. Install the plugin (downloads Python, agent, webui; builds the venv)
plugin install /boot/config/plugins/hermes/hermes.plg
```

Expect ~3 minutes on first install (downloads + venv build). Subsequent boots are <1 second because the cached artifacts are reused.

```bash
# 4. Configure your model/provider — interactive, needs a real terminal
hermes setup
```

The wizard walks you through picking a provider (Anthropic, OpenAI, Nous Portal, xAI, OpenRouter, local LM Studio / Ollama / custom endpoint, etc.) and supplying credentials. Hermes Agent's config lands in `/boot/config/plugins/hermes/.hermes/`, which is on flash and survives reboots.

```bash
# 5. (optional) Set a WebUI password before exposing it on your LAN
echo 'HERMES_WEBUI_PASSWORD=change-me' >> /boot/config/plugins/hermes/webui.env
/etc/rc.d/rc.hermes-webui restart
```

Then open the WebUI: **`http://<your-unraid-ip>:9000`**.

In the Unraid web UI, you can also manage everything visually from **Settings → Hermes**:
- Service status (running/stopped, start/stop/restart)
- Plugin settings (port, enable/disable WebUI and Gateway)
- Inline editors for `.hermes/config.yaml` and `.hermes/.env`
- Click-through "Open" button to the WebUI on the server's LAN IP

## What this plugin installs

| Component | Location | Persistence |
|-----------|----------|------------|
| Python 3.12 (hermetic, bundles OpenSSL/expat/libffi) | `/usr/local/hermes/python/` | RAM — re-extracted on every boot |
| Hermes Agent source | `/usr/local/hermes/hermes-agent/` | RAM — re-extracted on every boot |
| Hermes WebUI source | `/usr/local/hermes/hermes-webui/` | RAM — re-extracted on every boot |
| Combined venv (agent + webui) | `/usr/local/hermes/venv/` | RAM — rebuilt only when either commit changes |
| rc.d service scripts | `/etc/rc.d/rc.hermes-*` | RAM — reinstalled on every boot |
| `hermes` CLI wrapper | `/usr/local/sbin/hermes` | RAM — reinstalled on every boot |
| Settings page | `/usr/local/emhttp/plugins/hermes/` | RAM — reinstalled on every boot |
| **Pinned artifacts (Python + sources)** | `/boot/config/plugins/hermes/packages/` | Flash — survives reboot |
| **pip wheel cache** | `/boot/config/plugins/hermes/wheels/` | Flash — survives reboot |
| **User config / API keys** | `/boot/config/plugins/hermes/.hermes/` | Flash — survives reboot |
| **Plugin settings** | `/boot/config/plugins/hermes/hermes.cfg` | Flash — survives reboot |

Everything pinnable is pinned by SHA256 in `hermes.plg`: Python build, agent commit tarball, webui commit tarball. Bumping versions = bumping the entities at the top of `hermes.plg`.

The agent and webui share **one venv** because hermes-webui's bootstrap requires a Python that can import both webui deps AND hermes-agent — splitting them caused the webui to fall back to its own self-bootstrapped venv.

## Why this layout

- **`/boot` is FAT32** — no symlinks, slow writes, limited write cycles. Python venvs would either fail (default symlink mode) or thrash the USB stick.
- **`/usr/local` lives in RAM** — fast, supports symlinks, wiped on reboot. Unraid's `/etc/rc.d/rc.local` re-runs `plugin install` on every `.plg` at boot, so RAM contents are rebuilt automatically.
- **Python is from [python-build-standalone](https://github.com/astral-sh/python-build-standalone)** — Astral's relocatable, hermetic CPython build. Bundles its own OpenSSL/expat/libffi/etc., so it's immune to Unraid's system-library churn between versions. Unraid 7.2's Slackware base ships an older expat that breaks slackware-current's Python 3.12.
- **Marker files** (`.hermes-sha`, `.hermes-ver`) record which artifact version is currently provisioned. Re-runs of the install script skip work that's already done — typical re-install is <1 second, first install ~3 min.

## Configuration reference

The Settings page covers the common cases. The files behind it:

### `/boot/config/plugins/hermes/hermes.cfg` — plugin settings

```bash
WEBUI_ENABLED="yes"
GATEWAY_ENABLED="no"   # set to "yes" to enable messaging gateway
WEBUI_PORT="9000"
```

Apply changes:
```bash
/etc/rc.d/rc.hermes-webui   restart
/etc/rc.d/rc.hermes-gateway restart
```

### `/boot/config/plugins/hermes/webui.env` — WebUI environment

```bash
HERMES_WEBUI_PASSWORD=your-secure-password
HERMES_WEBUI_HOST=0.0.0.0
HERMES_WEBUI_PORT=9000
```

Then `/etc/rc.d/rc.hermes-webui restart`.

### `/boot/config/plugins/hermes/.hermes/config.yaml` — Hermes Agent config

Managed by `hermes setup` / `hermes model` / the Settings page editor. Stores model/provider choice, defaults, tool preferences.

### `/boot/config/plugins/hermes/.hermes/.env` — Hermes Agent secrets

API keys (Anthropic, OpenAI, xAI, etc.), messaging tokens. Read on every service start. **Written with mode 0600 when saved via the Settings page.**

### Messaging gateway (Telegram / Discord / Slack / Signal)

```bash
# 1. Enable in hermes.cfg → GATEWAY_ENABLED="yes" (or via Settings page)
# 2. Restart and configure platforms
/etc/rc.d/rc.hermes-gateway restart
hermes gateway setup
```

### Anthropic provider — uses Claude Code's OAuth token

Hermes' Anthropic OAuth path **does not have its own login**; it reads `~/.claude/.credentials.json` written by the Claude Code CLI. Two options:

- **Use an Anthropic API key instead** — pick "Anthropic" → "API key" in `hermes setup model`. No CLI dependency.
- **Use Claude Code OAuth** — install the Claude Code CLI separately (`curl -fsSL https://claude.ai/install.sh | bash`), run `claude setup-token`, then re-run `hermes setup model`.

## Service management

```bash
/etc/rc.d/rc.hermes-webui   {start|stop|restart|status}
/etc/rc.d/rc.hermes-gateway {start|stop|restart|status}
```

Or use the Settings → Hermes page.

## Updating

### Pull and apply

The plg lives at `/boot/config/plugins/hermes/` as a normal git clone. To pull the latest release:

```bash
cd /boot/config/plugins/hermes
git pull
plugin install /boot/config/plugins/hermes/hermes.plg forced
```

The install script is idempotent — it only re-extracts / rebuilds the artifacts whose pinned SHA changed:

- Only `hermes.plg` itself or rc scripts changed → re-install completes in <1 second
- `agentSHA` / `webuiSHA` bumped → ~30s to re-extract source + rebuild venv (wheels cached on flash)
- `pythonBUILD` bumped → ~1 min to re-download (~32 MB) and rebuild the venv

Current installed versions are shown in **Settings → Hermes → Versions**.

### Versioning scheme

`<!ENTITY version "YYYY.MM.DD">` at the top of `hermes.plg` — date-stamp the release. `plugin version /var/log/plugins/hermes.plg` reports this. Unraid's plugin manager surfaces "update available" if a higher-versioned plg is found at the `pluginURL` declared in the plg header.

The `<CHANGES>` block inside the plg holds the changelog; each release appends a new dated entry at the top.

### Cutting a release (maintainer flow)

```bash
cd /boot/config/plugins/hermes

# 1. Bump pinned versions in hermes.plg (any of these, as needed):
#    - pythonBUILD + pythonVER + pythonSHA
#    - agentSHA + agentTARSHA
#    - webuiSHA + webuiTARSHA
#    - version (today's date YYYY.MM.DD)

# Compute a SHA256 for any new URL:
#   curl -sL <url> | sha256sum

# 2. Add a CHANGES entry at the top of hermes.plg's <CHANGES> block

# 3. Test locally
plugin install /boot/config/plugins/hermes/hermes.plg forced

# 4. Commit and push
git add -A
git commit -m "Release YYYY.MM.DD: <summary>"
git push
```

## Uninstall

```bash
plugin remove hermes
```

Removes runtime files in `/usr/local/`, `/etc/rc.d/rc.hermes-*`, `/usr/local/sbin/hermes`, and the Settings page. **User config and cached packages are preserved on flash.**

To purge everything:
```bash
rm -rf /boot/config/plugins/hermes /boot/config/plugins/hermes.plg
```

## Troubleshooting

### WebUI not starting

```bash
tail -f /boot/config/plugins/hermes/.hermes/webui.log
/etc/rc.d/rc.hermes-webui status
```

### Rebuild the venv from scratch

```bash
rm -rf /usr/local/hermes/venv
plugin install /boot/config/plugins/hermes/hermes.plg forced
```

### Re-download a source tarball

```bash
rm -f /boot/config/plugins/hermes/packages/hermes-agent-*.tar.gz
plugin install /boot/config/plugins/hermes/hermes.plg forced
```

### Buttons in the Settings page don't do anything

Open browser DevTools → Network tab → click a button → check the response from `/plugins/hermes/include/action.php`. Should be JSON `{"ok":true,"msg":"..."}`. If you get HTML or a 500 error, check `/var/log/nginx/error.log`.

## Credits

- [Hermes Agent](https://github.com/NousResearch/hermes-agent) by NousResearch
- [Hermes WebUI](https://github.com/nesquena/hermes-webui) by nesquena
- Python distribution: [python-build-standalone](https://github.com/astral-sh/python-build-standalone) by Astral

## License

MIT
