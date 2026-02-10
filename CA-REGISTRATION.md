# Community Applications Registration Guide

This document explains how to submit the Custom SMB Shares plugin to Unraid's Community Applications (CA) for broader distribution.

## Overview

Community Applications is Unraid's app store, managed by Squid (Andrew Zawadzki). Plugins are added via XML template files that describe the plugin metadata.

## Prerequisites

Before submitting:

1. **Public GitHub Repository**: ✅ `cslemieux/unraid-custom-smb-shares`
2. **Working .plg file**: ✅ `custom.smb.shares.plg`
3. **Support channel**: ✅ GitHub Issues
4. **Plugin icon**: Need to add (see below)

## Template File

The CA template is located at `ca-template.xml` in this repository.

### Required Fields

| Field | Value | Notes |
|-------|-------|-------|
| `PluginURL` | Full URL to .plg file | Must match URL in .plg exactly |
| `PluginAuthor` | cslemieux | Your GitHub username |
| `Category` | Network Services: Tools:System | Use CA Categorizer tool |
| `Name` | Custom SMB Shares | Display name in CA |
| `Description` | Full description | Supports basic formatting |
| `Support` | GitHub Issues URL | Where users get help |
| `Icon` | URL to PNG image | 96x96 recommended |

### Optional Fields

| Field | Value | Notes |
|-------|-------|-------|
| `Beta` | False | Set True for beta releases |
| `Overview` | Short description | Condensed version |
| `Project` | GitHub repo URL | Project homepage |
| `IconFA` | share-alt | Font Awesome icon fallback |
| `MinVer` | 6.12.0 | Minimum Unraid version |
| `MaxVer` | (not set) | Maximum Unraid version |

## Submission Options

### Option 1: Create Your Own Template Repository (Recommended)

This is the preferred method for plugin authors:

1. **Create a new GitHub repository** for templates only
   - Name: `unraid-plugin-templates` or similar
   - Must contain ONLY .xml template files (no other XML)

2. **Add the template file**
   ```bash
   # In your new template repo
   cp ca-template.xml custom.smb.shares.xml
   git add custom.smb.shares.xml
   git commit -m "Add Custom SMB Shares template"
   git push
   ```

3. **Contact Squid** to add your repository to CA
   - Post in the [Community Applications support thread](https://forums.unraid.net/topic/38582-plug-in-community-applications/)
   - Or PM Squid on the Unraid forums
   - Provide: Repository URL, brief description

### Option 2: Submit to selfhosters/unRAID-CA-templates

**Note**: This repo is slowing down maintenance. They recommend Option 1.

1. **Fork the repository**
   ```bash
   # Fork https://github.com/selfhosters/unRAID-CA-templates on GitHub
   git clone https://github.com/YOUR_USERNAME/unRAID-CA-templates
   cd unRAID-CA-templates
   ```

2. **Add your template**
   ```bash
   cp /path/to/ca-template.xml templates/custom.smb.shares.xml
   git add templates/custom.smb.shares.xml
   git commit -m "Add Custom SMB Shares plugin template"
   git push origin master
   ```

3. **Create Pull Request**
   - Go to your fork on GitHub
   - Click "New Pull Request"
   - Provide description of the plugin

### Option 3: Direct Contact with Squid

For established plugins, you can directly contact Squid:

1. Create a support thread on Unraid forums (if not using GitHub Issues)
2. PM Squid with:
   - Plugin name and description
   - URL to .plg file
   - URL to template XML
   - Your GitHub repository URL

## Plugin Icon

CA displays a 96x96 icon. Create one at:
```
source/usr/local/emhttp/plugins/custom.smb.shares/images/custom.smb.shares.png
```

Requirements:
- PNG format
- 96x96 pixels (or larger, CA will resize)
- Host on GitHub (stable URL)

## Verification Checklist

Before submitting, verify:

- [ ] `.plg` file installs correctly on fresh Unraid
- [ ] Plugin works on Unraid 6.12+ and 7.x
- [ ] `PluginURL` in template matches URL in `.plg` file exactly
- [ ] Support URL is accessible
- [ ] Icon URL returns valid image
- [ ] Description is accurate and helpful
- [ ] Category matches (use CA Categorizer if unsure)

## After Submission

1. **Wait for review** - Squid reviews submissions periodically
2. **Respond to feedback** - May need template adjustments
3. **Monitor support channel** - Users will find your plugin via CA

## Updating the Template

When releasing new versions:

1. **No template update needed** for version bumps
   - CA reads version from .plg file automatically
   - Changelog comes from .plg `<CHANGES>` section

2. **Update template only for**:
   - Description changes
   - Category changes
   - Icon changes
   - Support URL changes

## References

- [Plugin Templates for CA / Appstore](https://forums.unraid.net/topic/42808-plugin-templates-for-ca-appstore/) - Official schema
- [Community Applications Plugin](https://forums.unraid.net/topic/38582-plug-in-community-applications/) - Support thread
- [selfhosters/unRAID-CA-templates](https://github.com/selfhosters/unRAID-CA-templates) - Community template repo
- [Writing a template compatible for Unraid](https://selfhosters.net/docker/templating/templating/) - Detailed guide

## Quick Start

To submit this plugin to CA:

```bash
# 1. Create a template-only repository on GitHub
# Name: cslemieux/unraid-plugin-templates

# 2. Add the template
cp ca-template.xml custom.smb.shares.xml
git add custom.smb.shares.xml
git commit -m "Add Custom SMB Shares template"
git push

# 3. Post in CA support thread or PM Squid with repo URL
```
