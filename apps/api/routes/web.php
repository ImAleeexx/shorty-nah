<?php

declare(strict_types=1);

// Nothing is served through the web group. The bare host is answered by the
// redirect path in routes/redirect.php, which needs neither a session nor a
// CSRF token, and the API lives in routes/api.php.
