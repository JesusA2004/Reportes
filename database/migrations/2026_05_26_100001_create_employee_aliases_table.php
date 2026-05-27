<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('employee_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnDelete();
            $table->string('alias_name', 255);
            $table->string('normalized_alias', 191)->index();
            $table->string('source', 50)->default('manual'); // manual | auto_exact | confirmed_match
            $table->decimal('confidence', 5, 2)->default(1.00);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['employee_id', 'normalized_alias'], 'ea_employee_alias_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_aliases');
    }
};
