# WP Diagnostics CLI Usage

The plugin exposes a full WP-CLI namespace:

```bash
wp diagnostics
```

## Core Commands

```bash
wp diagnostics status
wp diagnostics system --format=table
wp diagnostics logs --limit=50
wp diagnostics performance --verbose
```

## Malware Commands

```bash
wp diagnostics malware scan --verbose
wp diagnostics malware clean --path="/absolute/path/to/file.php" --yes
```

## Database Commands

```bash
wp diagnostics db optimize --yes
wp diagnostics db optimize --table=wp_options --yes
wp diagnostics db repair --table=wp_posts --yes
```

## File Commands

```bash
wp diagnostics file scan --path="/var/www/html" --query="eval(" --limit=100
wp diagnostics file integrity
```

## Cache Command

```bash
wp diagnostics cache clear
```

## Security Notes

- Destructive operations require confirmation unless `--yes` is provided.
- Database query execution is restricted to safe read-only statements in service layer.
- CLI actions are logged through `Operation_Logger` with action, status, and context.
- File quarantine and restore are restricted to safe paths under WordPress root/quarantine.
