<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use PDO;
use Sabre\CalDAV;
use Sabre\CardDAV;
use Sabre\DAV;
use Sabre\DAVACL;

final class ServerFactory
{
    public static function create(PDO $pdo, Auth $auth, string $username): DAV\Server
    {
        // The username is already validated against the credential database before this call.
        $directory = Config::dataDir().'/files/'.$username;
        if (!is_dir($directory)) {
            throw new \RuntimeException('Account storage is missing; contact administrator');
        }
        $userPdo = Database::userDatabase($username);
        $principals = new DAVACL\PrincipalBackend\PDO($userPdo);
        $calendars = new ValidatedCalendarBackend($userPdo);
        $addresses = new PaginatedCardDavBackend($userPdo);

        $guard = new PrivateFilesystemGuard($directory);
        $server = new DAV\Server([
            new CalDAV\Principal\Collection($principals),
            new CalDAV\CalendarRoot($principals, $calendars),
            new CardDAV\AddressBookRoot($principals, $addresses),
            new PrivateDirectory($directory, $guard, FileQuota::forAccount($guard, $username)),
        ]);
        $server->setBaseUri(Config::baseUri());
        $server->debugExceptions = false;
        $server->addPlugin(new DAV\Auth\Plugin($auth, 'GoreeCloud DAV'));
        $server->addPlugin(new DAV\Sync\Plugin());
        $acl = new DAVACL\Plugin();
        $acl->hideNodesFromListings = true;
        // Files are private because authenticated requests receive ONLY their own FS root.
        // Never mount an unscoped shared FS tree while this setting is enabled.
        $server->addPlugin($acl);
        $server->addPlugin(new DAV\Locks\Plugin(new DAV\Locks\Backend\PDO($userPdo)));
        $server->addPlugin(new CalDAV\Plugin());
        $server->addPlugin(new CalDAV\ICSExportPlugin());
        $server->addPlugin(new CardDAV\Plugin());
        $server->addPlugin(new CardDAV\VCFExportPlugin());
        return $server;
    }
}
