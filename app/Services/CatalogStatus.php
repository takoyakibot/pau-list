<?php

namespace App\Services;

enum CatalogStatus
{
    case Reused;
    case Synced;
    case Imported;
    case BudgetExhausted;
    case Failed;
    case NotFound;
}
