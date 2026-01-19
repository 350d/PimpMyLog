# Pimp My Log

**Pimp My Log** is a web application for viewing and analyzing web server and application logs. It supports Apache, Nginx, PHP, MySQL, and other log types with a convenient web interface.

## Features

- 📊 Real-time log viewing with automatic refresh
- 🔍 Powerful search with regular expression support
- 🎨 User-friendly web interface with filtering and sorting
- 🔐 Authentication and user management system
- 📱 Responsive design for mobile devices
- 📤 Export logs in various formats (CSV, JSON, XML, RSS, ATOM)
- 🧩 Block-based parsing support for multi-line logs (MySQL Slow Log, etc.)
- 🌍 Multi-language support
- ⚙️ Flexible configuration via JSON/PHP configuration files

## Requirements

- **PHP** >= 5.2.0 (PHP 8.0+ recommended)
- Web server (Apache, Nginx, IIS)
- PHP extensions: `json`, `mbstring` (optional)

## Installation

### Method 1: Clone Repository

```bash
git clone https://github.com/350d/PimpMyLog.git
cd PimpMyLog
```

### Method 2: Composer

```bash
composer create-project potsky/pimp-my-log
```

## Configuration

1. **Copy the configuration file:**
   ```bash
   cp cfg/config.example.php config.user.json
   ```

2. **Open the web interface:**
   - Open `http://your-domain.com/path-to-pimpmylog/` in your browser
   - Follow the setup wizard instructions

3. **Configure log access:**
   - Specify paths to your log files in the configuration
   - Configure regular expressions for parsing (examples included)
   - Set up authentication if needed

## Configuration Examples

### Apache Error Log

```json
{
  "files": {
    "apache_error": {
      "display": "Apache Error",
      "path": "/var/log/apache2/error.log",
      "refresh": 5,
      "max": 50,
      "format": {
        "regex": "|^\\[(.*)\\] \\[(.*)\\] (\\[client (.*)\\] )*((?!\\[client ).*)(, referer: (.*))*$|U",
        "match": {
          "Date": 1,
          "Severity": 2,
          "IP": 4,
          "Log": 5,
          "Referer": 7
        }
      }
    }
  }
}
```

### PHP Error Log (ServerPilot)

```json
{
  "files": {
    "php_error": {
      "display": "PHP Error Log",
      "path": "/srv/users/serverpilot/apps/YOURAPP/log/YOURAPP_php8.5.error.log",
      "refresh": 5,
      "max": 100,
      "format": {
        "regex": "|^\\[(.*)\\] PHP (.*): (.*) in (.*) on line (.*)$|",
        "match": {
          "Date": 1,
          "Severity": 2,
          "Message": 3,
          "File": 4,
          "Line": 5
        }
      }
    }
  }
}
```

### MySQL Slow Log

```json
{
  "files": {
    "mysqlslow": {
      "display": "MySQL Slow Log",
      "path": "/var/log/mysql/mysql-slow.log",
      "refresh": 60,
      "max": 200,
      "format": {
        "block_start": "# Time:",
        "regex": "/# Time: ([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})\\.[0-9]+Z.*?# Query_time: ([0-9.]+)\\s+Lock_time: ([0-9.]+).*?Rows_examined: ([0-9]+)\\n([\\s\\S]*?)(?=# Time:|\\z)/s",
        "match": {
          "Date": {
            "Y": 1,
            "m": 2,
            "d": 3,
            "H": 4,
            "i": 5,
            "s": 6
          },
          "Time": 7,
          "Lock": 8,
          "Rows": 9,
          "SQL": 10
        }
      }
    }
  }
}
```

## ServerPilot — Log Locations

If your site is hosted on ServerPilot, logs are typically located in the following places:

| Log Type | Path |
|----------|------|
| PHP Error Log | `/srv/users/serverpilot/apps/YOURAPP/log/YOURAPP_php8.5.error.log` |
| PHP Access Log | `/srv/users/serverpilot/apps/YOURAPP/log/YOURAPP_php8.5.access.log` |
| Apache Error Log | `/srv/users/serverpilot/apps/YOURAPP/log/YOURAPP_apache.error.log` |
| Apache Access Log | `/srv/users/serverpilot/apps/YOURAPP/log/YOURAPP_apache.access.log` |
| PHP-FPM Service Log | `/var/log/php8.5-fpm-sp.log` |

**Note:** Replace `YOURAPP` with your application name in ServerPilot. PHP version may vary (php7.4, php8.0, php8.1, php8.2, php8.3, php8.5, etc.).

### ServerPilot Configuration Example

```json
{
  "files": {
    "serverpilot_php_error": {
      "display": "PHP Error (ServerPilot)",
      "path": "/srv/users/serverpilot/apps/myapp/log/myapp_php8.5.error.log",
      "refresh": 5,
      "max": 100,
      "notify": true,
      "format": {
        "regex": "|^\\[(.*)\\] PHP (.*): (.*) in (.*) on line (.*)$|",
        "match": {
          "Date": 1,
          "Severity": 2,
          "Message": 3,
          "File": 4,
          "Line": 5
        },
        "types": {
          "Date": "date:H:i:s",
          "Severity": "badge:severity",
          "Message": "pre",
          "File": "txt",
          "Line": "numeral"
        }
      }
    },
    "serverpilot_php_access": {
      "display": "PHP Access (ServerPilot)",
      "path": "/srv/users/serverpilot/apps/myapp/log/myapp_php8.5.access.log",
      "refresh": 0,
      "max": 50,
      "format": {
        "regex": "|^(.*) - \\[(.*)\\] \"(.*) (.*) (.*)\" ([0-9]*) (.*) - (.*) (.*) (.*) \"(.*)\" \"(.*)\"|",
        "match": {
          "IP": 1,
          "Date": 2,
          "Method": 3,
          "URL": 4,
          "Protocol": 5,
          "Code": 6,
          "Size": 7,
          "Referer": 11,
          "UA": 12
        },
        "types": {
          "Date": "date:H:i:s",
          "IP": "ip:geo",
          "Code": "badge:http",
          "Size": "numeral:0b"
        }
      }
    }
  }
}
```

## Project Structure

```
PimpMyLog/
├── cfg/              # Configuration files and examples
├── css/              # Stylesheets
├── fonts/            # Fonts
├── img/              # Images and icons
├── inc/              # PHP libraries and classes
│   ├── classes/      # Core classes (LogParser, Sentinel, Session)
│   └── ...
├── js/               # JavaScript files
├── lang/             # Localization files
├── tmp/              # Temporary files
├── index.php         # Main entry point
├── config.user.json  # Your configuration file (created during setup)
└── README.md         # This file
```

## Security

- Set proper file permissions for configuration files
- Use authentication to protect log access
- Restrict directory access via web server (`.htaccess` for Apache)
- Do not place configuration files in public directories

## License

GPL-3.0+

## Support

- **Issues:** [GitHub Issues](https://github.com/350d/PimpMyLog/issues)
- **Fork:** Based on [potsky/PimpMyLog](https://github.com/potsky/PimpMyLog)

## Changes in This Version

- ✅ Block-based parsing support for multi-line logs (MySQL Slow Log)
- ✅ PHP 8.0+ compatibility
- ✅ Improved configuration parsing error handling
- ✅ ServerPilot configuration examples
