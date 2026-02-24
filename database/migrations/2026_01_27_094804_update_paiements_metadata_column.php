<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Conversion metadata en jsonb
        DB::statement('
            ALTER TABLE paiements
            ALTER COLUMN metadata TYPE jsonb
            USING metadata::jsonb
        ');

        // Conversion fedapay_response en jsonb
        DB::statement('
            ALTER TABLE paiements
            ALTER COLUMN fedapay_response TYPE jsonb
            USING fedapay_response::jsonb
        ');

        DB::statement('
            ALTER TABLE paiements
            ALTER COLUMN fedapay_response DROP NOT NULL
        ');
    }

    public function down(): void
    {
        DB::statement('
            ALTER TABLE paiements
            ALTER COLUMN metadata TYPE text
            USING metadata::text
        ');

        DB::statement('
            ALTER TABLE paiements
            ALTER COLUMN fedapay_response TYPE text
            USING fedapay_response::text
        ');
    }
};
