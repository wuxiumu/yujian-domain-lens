<?php
// Copy to traffic.local.php. The local file is ignored by Git and stays outside public/.
return [
    'tabapi_key' => '',
    // Lifetime maximum outgoing attempts per key, shared by every visitor. 30 * 3 = 90 credits.
    'max_lookups' => 30,
];
