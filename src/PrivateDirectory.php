<?php

declare(strict_types=1);

namespace GoreeCloud\DAV;

use Sabre\DAV\FS\Directory;

final class PrivateDirectory extends Directory
{
    public function getName(): string
    {
        return 'files';
    }
}
