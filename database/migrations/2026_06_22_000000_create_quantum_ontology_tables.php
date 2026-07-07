<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The database connection that should be used by the migration.
     *
     * @var string
     */
    protected $connection = 'agent_memory_sqlite';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('memory_nodes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('type');
            $table->text('content');
            $table->double('phase_angle')->default(0.0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('memory_vectors', function (Blueprint $table) {
            $table->string('node_id')->primary();
            $table->binary('embedding');
            $table->foreign('node_id')->references('id')->on('memory_nodes')->onDelete('cascade');
        });

        Schema::create('memory_edges', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('source_id');
            $table->string('target_id');
            $table->string('edge_type');
            $table->double('coherence_factor')->default(1.0);
            $table->foreign('source_id')->references('id')->on('memory_nodes')->onDelete('cascade');
            $table->foreign('target_id')->references('id')->on('memory_nodes')->onDelete('cascade');
        });

        Schema::create('entanglement_pairs', function (Blueprint $table) {
            $table->string('node_a_id');
            $table->string('node_b_id');
            $table->double('entanglement_force')->default(1.0);
            $table->primary(['node_a_id', 'node_b_id']);
            $table->foreign('node_a_id')->references('id')->on('memory_nodes')->onDelete('cascade');
            $table->foreign('node_b_id')->references('id')->on('memory_nodes')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entanglement_pairs');
        Schema::dropIfExists('memory_edges');
        Schema::dropIfExists('memory_vectors');
        Schema::dropIfExists('memory_nodes');
    }
};
