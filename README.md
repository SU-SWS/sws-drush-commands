# SWS Drush Commands

A collection of Drush commands to provide BLT-like automation for Stanford Web Services projects, replacing Acquia BLT workflows with Drush-native equivalents.

## Installation
Install via Composer (usually as part of your project dependencies):

```
composer require su-sws/sws-drush-commands
```

## Usage

### Migrate BLT Config to Drush
Migrate your existing BLT config to Drush config files:

```
drush sws:migrate-blt [--app-key=YOUR_KEY --app-secret=YOUR_SECRET]
```

---

### Multisite Management
Create a new multisite (replaces `blt multisite`):

```
drush sws:multisite:new-site SITE_NAME
```

---

### Build Drush Aliases
Build Drush aliases from Acquia Cloud (replaces `blt aliases`):

```
drush sws:acquia:alias-build
```

Additional options: `--app-id=YOUR_APP_ID`, `--app-key=YOUR_KEY`, `--app-secret=YOUR_SECRET`.

---

### Artifact Deployment
Build and deploy a code artifact (replaces `blt deploy`):

```
drush sws:artifact:deploy
```

Additional options: `--git-url="REPO_URL"`, `--branch="BRANCH_NAME"`, `--tag=TAG_NAME`, `--commit-msg="Message"`.

---

### Site Sync
Sync a site from production to local (replaces `blt drupal:sync`):

```
drush sws:site:sync --site=SITE_NAME [--with-files]
```

---

### List All SWS Commands

```bash
drush list | grep sws:
```

---

## Command Mapping Table

| BLT Command                  | SWS Drush Command Example                        |
|------------------------------|-------------------------------------------------|
| blt multisite                | drush sws:multisite:new-site SITE_NAME          |
| blt aliases                  | drush sws:acquia:alias-build ...                |
| blt deploy                   | drush sws:artifact:deploy ...                   |
| blt drupal:sync              | drush sws:site:sync ...                         |
| blt config:migrate           | drush sws:migrate-blt ...                       |

---

## More Information
- See the [Annotated Command documentation](https://github.com/consolidation/annotated-command?tab=readme-ov-file#command-hook) for Drush command hooks and advanced usage.

---

## License
[GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
