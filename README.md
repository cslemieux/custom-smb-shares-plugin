# Custom SMB Shares - Unraid Plugin

Create and manage custom SMB shares on your Unraid server with a user-friendly web interface.

## Features

### Share Management
- **Create, Edit, Delete**: Full CRUD operations through the Unraid WebGUI
- **Clone Shares**: Quickly duplicate existing shares with one click
- **Enable/Disable Toggle**: Temporarily disable shares without deleting them
- **Import/Export**: Backup and restore share configurations as JSON

### Access Control
- **Security Modes**: Public, Secure (read-only default), or Private
- **User/Group Browser**: Visual picker for setting permissions
- **Per-User Access**: Read Only, Read/Write, or No Access per user

### Advanced Options
- **macOS Support**: Enhanced compatibility with Fruit VFS module
- **Time Machine**: Configure shares as Time Machine backup destinations
- **Hidden Shares**: Make shares accessible but not browseable
- **Permission Masks**: Fine-grained file/directory permission control
- **Host Allow/Deny**: IP-based access restrictions

### User Experience
- **Path Browser**: Visual directory picker for selecting share paths
- **Real-time Validation**: Instant feedback on form errors
- **Loading States**: Visual feedback during operations
- **Toast Notifications**: Success/error messages for all actions
- **Feature Icons**: Quick visual indicators for share settings

### Backup & Recovery
- **Automatic Backups**: Created before import, edit, and delete operations
- **Manual Backups**: Create backups on demand
- **Backup Management**: View, restore, or delete backups from Settings
- **Configurable Retention**: Set how many backups to keep

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

Navigate to **Tasks** > **SMB Shares** (or search for "SMB Shares")

### Creating a Share

1. Click **Add Share**
2. Enter share details:
   - **Path**: Click to browse and select a directory under `/mnt/`
   - **Share Name**: Auto-populated from folder name, or enter custom name
   - **Comment**: Optional description visible when browsing
3. Configure export options:
   - **Export**: Yes, Hidden, Time Machine, or No
   - **Security**: Public, Secure, or Private
4. (Optional) Toggle **Advanced View** for:
   - Case sensitivity settings
   - Hide dot files option
   - Enhanced macOS support
   - Permission masks
   - Force user/group
   - Host allow/deny lists
5. Click **Add Share**

### Managing Shares

| Action | Description |
|--------|-------------|
| **Toggle** | Enable/disable share without deleting |
| **Edit** | Modify share settings |
| **Clone** | Create a copy with "-copy" suffix |
| **Delete** | Remove share (creates backup first) |

### Import/Export

- **Export**: Download current configuration as JSON
- **Import**: Paste JSON or upload file to restore/migrate shares

### Settings

Navigate to **Settings** page to:
- Enable/disable the plugin globally
- Configure backup retention count
- View and manage backups

## Configuration Files

| File | Location | Purpose |
|------|----------|---------|
| Share definitions | `/boot/config/plugins/custom.smb.shares/shares.json` | JSON array of share configurations |
| Plugin settings | `/boot/config/plugins/custom.smb.shares/settings.cfg` | Plugin enable state, backup count |
| Samba config | `/boot/config/plugins/custom.smb.shares/smb-custom.conf` | Generated Samba include file |
| Backups | `/boot/config/plugins/custom.smb.shares/backups/` | Timestamped JSON backup files |

## Feature Icons Reference

| Icon | Meaning |
|------|---------|
| 🍎 | macOS support enabled (Fruit VFS) |
| ⏰ | Time Machine backup destination |
| 👁️ | Hidden share (accessible but not browseable) |
| 🔒 | Private security (specified users only) |
| 🔓 | Secure security (all read, specified write) |

## Troubleshooting

### Share not accessible

1. Verify share is enabled (toggle is on)
2. Verify Samba is running (green indicator in header)
3. Check the path exists and is readable
4. Verify user permissions are configured correctly

### Changes not taking effect

1. Click **Reload Samba** in the plugin header
2. Wait a few seconds for the service to restart
3. Reconnect from your client

### Validation errors

| Error | Solution |
|-------|----------|
| Path must start with /mnt/ | Only paths under `/mnt/` are allowed |
| Share name already exists | Choose a unique name |
| Path does not exist | Create the directory first or select an existing one |
| Invalid share name | Use only letters, numbers, hyphens, and underscores |

### Time Machine not working

1. Ensure **Export** is set to "Time Machine" or "Time Machine, hidden"
2. macOS support (Fruit) is automatically enabled for Time Machine shares
3. Set appropriate **Volume size limit** if needed
4. Use **Private** security and grant your user Read/Write access

## Development

### Running Tests

```bash
# All tests
composer test

# Unit tests only
composer test:unit

# Integration tests only
composer test:integration

# E2E tests (requires ChromeDriver)
composer test:e2e
```

### Building

```bash
# Build .txz package
./build.sh

# Deploy to test server
./deploy.sh
```

## Changelog

### v1.0.0 (2024-12-14)
- Initial release
- Full share CRUD operations
- Enable/disable toggle with loading states
- Clone share functionality
- Import/export configuration
- Backup management with configurable retention
- macOS/Time Machine support
- Advanced permission settings
- Comprehensive test suite (274 tests)

## Support

- [GitHub Issues](https://github.com/cslemieux/custom-smb-shares-plugin/issues)
- [Unraid Forums](https://forums.unraid.net/)

## License

MIT License - See [LICENSE](LICENSE) for details
