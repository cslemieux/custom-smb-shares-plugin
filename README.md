# Custom SMB Shares - Unraid Plugin

Create and manage custom SMB shares on your Unraid server with a user-friendly web interface.

## Features

- **Easy Share Management**: Create, edit, and delete SMB shares through the Unraid WebGUI
- **Access Control**: User/group browser with Read Only and Read/Write permission levels
- **Path Browser**: Visual directory picker for selecting share paths
- **Validation**: Real-time validation with helpful error messages
- **Samba Integration**: Automatic configuration generation and service reload

## Requirements

- Unraid OS 6.12 or later
- Samba service enabled

## Installation

### From Community Applications (Recommended)

1. Open Unraid WebGUI
2. Navigate to **Apps** (Community Applications)
3. Search for "Custom SMB Shares"
4. Click **Install**

### Manual Installation

1. Navigate to **Plugins** > **Install Plugin**
2. Paste this URL:
   ```
   https://raw.githubusercontent.com/cslemieux/custom-smb-shares-plugin/main/custom.smb.shares.plg
   ```
3. Click **Install**

Or download the `.plg` file from [Releases](https://github.com/cslemieux/custom-smb-shares-plugin/releases) and upload it.

## Usage

### Accessing the Plugin

Navigate to **Settings** > **Network Services** > **SMB Shares**

### Creating a Share

1. Click **Add Share**
2. Enter share details:
   - **Path**: Click Browse to select a directory under `/mnt/`
   - **Share Name**: Alphanumeric characters, hyphens, and underscores
   - **Comment**: Optional description
3. Configure access control (optional):
   - Search for users/groups
   - Set permission level (Read Only or Read/Write)
4. Click **Add**

### Editing a Share

1. Click **Edit** next to the share
2. Modify settings as needed
3. Click **Update**

### Deleting a Share

1. Click **Delete** next to the share
2. Confirm deletion

## Configuration Files

| File | Location | Purpose |
|------|----------|---------|
| Share definitions | `/boot/config/plugins/custom.smb.shares/shares.json` | JSON array of share configurations |
| Samba config | `/boot/config/plugins/custom.smb.shares/smb-extra.conf` | Generated Samba include file |

## Troubleshooting

### Share not accessible

1. Verify Samba is running (green indicator in plugin header)
2. Check the path exists and is readable
3. Verify user permissions are configured correctly

### Changes not taking effect

1. Click **Reload Samba** in the plugin header
2. Wait a few seconds for the service to restart
3. Reconnect from your client

### Validation errors

- **Path must start with /mnt/**: Only paths under `/mnt/` are allowed
- **Share name already exists**: Choose a unique name
- **Path does not exist**: Create the directory first or select an existing one

## Support

- [GitHub Issues](https://github.com/cslemieux/custom-smb-shares-plugin/issues)
- [Unraid Forums](https://forums.unraid.net/)

## License

MIT License - See [LICENSE](LICENSE) for details
