<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

class ArchiveableBuilder extends Builder
{
    public function delete()
    {
        throw new LogicException('Archiveable records must be archived, not deleted.');
    }
}
