<?php

class AdminerLoginPasswordLess
{
    private static $dbConf = null;
    private const DRIVERS = [
        "mysql" => "server",
        "mysqli" => "server",
        "pdo_mysql" => "server",
        "sqlite3" => "sqlite",
        "pdo_sqlite" => "sqlite",
        "sqlite2" => "sqlite2",
        "pgsql" => "pgsq",
        "pdo_pgsql" => "pgsq",
        "oci8" => "oracle",
        "pdo_oci" => "oracle",
        "mssql" => "mssql",
        "pdo_sqlsrv" => "mssql",
        "mongo" => "mongo",
        "elastic" => "elastic",
    ];

    public static function setDbConf($dbConf)
    {
        if (is_string($dbConf)) {
            $dbConf = parse_url($dbConf);
        }
        if (is_array($dbConf)) {
            static::$dbConf = $dbConf;
        }
    }

    public static function isConfigured(): bool
    {
        return static::$dbConf !== null;
    }

    public static function resolveDriver(): ?string
    {
        $scheme = static::$dbConf['scheme'] ?? null;
        if (!is_string($scheme) || !isset(self::DRIVERS[$scheme])) {
            return null;
        }

        return self::DRIVERS[$scheme];
    }

    public function login($username, $password)
    {
        return true;
    }

    public function credentials()
    {
        $host = static::$dbConf['host'] ?? '';
        if ($host !== '' && isset(static::$dbConf['port'])) {
            $host .= ":" . static::$dbConf['port'];
        }
        $user = static::$dbConf['user'] ?? '';
        $pass = static::$dbConf['pass'] ?? '';
        return [$host, $user, $pass];
	}

    public function database()
    {
        return ltrim((string) (static::$dbConf['path'] ?? ''), '/');
    }

    public function loginForm()
    {
        $driver = self::resolveDriver();
        if ($driver === null) {
            return null;
        }

        $data = [
            "driver" => $driver,
            "server" => "",
            "username" => "",
            "password" => "",
            "db" => $this->database(),
        ];
        foreach ($data as $var => $value) {
            echo sprintf("<input type=\"hidden\" name=\"auth[%s]\" value=\"%s\">\n", htmlspecialchars($var), htmlspecialchars($value));
        }
        $nonce = \adminer\get_nonce();
        echo "<input type=\"submit\" value=\"Login\">\n";
        echo "<script type=\"text/javascript\" nonce=\"$nonce\">\n";
        echo "window.onload = () => {qs('form').submit()}\n";
        echo "</script>\n";
        return true;
    }
}

function ensure_adminer_session_started(): void
{
    if (session_status() === PHP_SESSION_ACTIVE && session_name() === 'adminer_sid') {
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/adminer');
    $cookiePath = preg_replace('~\?.*~', '', $requestUri) ?: '/';
    $https = $_SERVER['HTTPS'] ?? null;
    $secure = is_string($https) && $https !== '' && strcasecmp($https, 'off') !== 0;

    session_cache_limiter('');
    session_name('adminer_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function clear_adminer_invalid_login(): void
{
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!is_string($remoteAddr) || $remoteAddr === '') {
        return;
    }

    foreach (glob(sys_get_temp_dir() . '/adminer.invalid*') ?: [] as $path) {
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            continue;
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            continue;
        }

        $invalidLogins = @unserialize(stream_get_contents($handle));
        if (is_array($invalidLogins) && array_key_exists($remoteAddr, $invalidLogins)) {
            unset($invalidLogins[$remoteAddr]);
            rewind($handle);
            fwrite($handle, serialize($invalidLogins));
            ftruncate($handle, ftell($handle));
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function bootstrap_adminer_login(): void
{
    if (!AdminerLoginPasswordLess::isConfigured()) {
        return;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return;
    }

    if (!isset($_GET['username']) || !is_string($_GET['username'])) {
        return;
    }

    $driver = AdminerLoginPasswordLess::resolveDriver();
    if ($driver === null) {
        return;
    }

    $database = isset($_GET['db']) && is_string($_GET['db'])
        ? $_GET['db']
        : (new AdminerLoginPasswordLess())->database();

    ensure_adminer_session_started();
    clear_adminer_invalid_login();
    $_SESSION['pwds'][$driver][''][$_GET['username']] = '';
    if ($database !== '') {
        $_SESSION['db'][$driver][''][$_GET['username']][$database] = true;
    }
}

function parse_users(string $usersPass): ?array
{
    $users = [];
    if ($usersPass !== '') {
        foreach (preg_split('/\s+/', $usersPass) as $userPass) {
            if ($userPass === '') {
                continue;
            }
            $parsed = explode(":", $userPass);
            if (count($parsed) !== 2) {
                return null;
            }
            list($username, $password) = $parsed;
            $username = urldecode($username);
            $password = urldecode($password);
            if (!isset($users[$username])) {
                $users[$username] = [];
            }
            $users[$username][] = $password;
        }
    }
    return $users;
}

function build_auth_callback($httpAuthorize): ?callable
{
    if ($httpAuthorize === null) {
        return null;
    }
    if (is_callable($httpAuthorize)) {
        return $httpAuthorize;
    }
    if (is_string($httpAuthorize)) {
        $users = parse_users($httpAuthorize);
        if ($users !== null) {
            return function ($user, $pass) use ($users) {
                foreach ($users[$user] ?? [] as $password) {
                    if ($password === $pass) {
                        return true;
                    }
                }
                return false;
            };
        }
    }

    return function($username, $password) {
        return false;
    };
}

function http_authorize($httpAuthorize)
{
    $httpAuthorize = build_auth_callback($httpAuthorize);
    if ($httpAuthorize !== null) {
        $username = $_SERVER['PHP_AUTH_USER'] ?? null;
        $password = $_SERVER['PHP_AUTH_PW'] ?? null;
        if (!is_string($username)) {
            $username = null;
        }
        if (!is_string($password)) {
            $password = null;
        }
        $authorized = $httpAuthorize($username, $password) ? true : false;
    } else {
        $authorized = true;
    }
    if (!$authorized) {
        header('WWW-Authenticate: Basic realm="Adminer"');
        header('HTTP/1.0 401 Unauthorized');
        echo "<!doctype html>\n<html><head><title>Unauthorized</title></head><body><h1>401 Unauthorized</h1></body></html>";
        exit;
    }
}

http_authorize($httpAuth ?? null);
AdminerLoginPasswordLess::setDbConf($dbConf ?? null);

if (AdminerLoginPasswordLess::isConfigured()) {
    $database = (new AdminerLoginPasswordLess())->database();
    $hasUsername = isset($_GET['username']) && is_string($_GET['username']);
    $hasDatabase = isset($_GET['db']) && is_string($_GET['db']) && $_GET['db'] !== '';

    if (!$hasUsername || !$hasDatabase) {
        $queryString = 'username=' . urlencode($hasUsername ? $_GET['username'] : '') . '&db=' . urlencode($database);
        $uri = preg_replace('/\?.*$/', '', $_SERVER['REQUEST_URI']) . '?' . $queryString;
        header('Location: ' . $uri);
        exit;
    }
}

bootstrap_adminer_login();

chdir(__DIR__);

if (defined("SID") && session_status() !== PHP_SESSION_ACTIVE){
    session_start();
}

include __DIR__.'/adminer.php';
