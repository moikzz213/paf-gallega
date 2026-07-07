<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceDocument extends Model
{
    protected $fillable = ['invoice_id', 'uploaded_by', 'original_name', 'file_path', 'mime_type', 'size'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
