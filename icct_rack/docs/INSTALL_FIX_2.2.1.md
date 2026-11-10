# Install Fix in v2.2.1

Cacti 1.2.30 reads `longname` from the plugin version metadata and inserts it into `plugin_config.name`. The previous 2.2.0 INFO file omitted `longname`, which caused MariaDB to reject the install with `Column name cannot be null`.

Version 2.2.1 restores the standard Cacti INFO fields and adds defensive defaults in `plugin_icct_rack_version()`.
