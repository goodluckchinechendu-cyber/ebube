<?php
/**
 * Legacy root entry — real API bootstrap lives in backend/.
 * Keep this so old paths that include /config.php do not fatally error.
 */
require_once __DIR__ . '/backend/config.php';
