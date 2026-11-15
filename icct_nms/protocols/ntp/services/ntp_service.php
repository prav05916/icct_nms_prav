<?php
/**
 * NTP service shim forwarding to monitoring/services/ntp_service.php.
 * Preserves compatibility for any component referencing protocols/ntp path.
 */
require_once __DIR__ . '/../../../monitoring/services/ntp_service.php';

