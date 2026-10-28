<?php

// app/Models/BranchStock.php
namespace App\Models;
use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class BranchStock extends Model
{
    use BelongsToBusiness;
    protected $fillable = [];
}
