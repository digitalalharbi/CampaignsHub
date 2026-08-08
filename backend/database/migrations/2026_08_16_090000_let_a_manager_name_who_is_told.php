<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a manager wants informed — MAIL-010.
 *
 * ## The gap this fills
 *
 * Everything about notifications in this product is self-service: a person opens their own settings
 * and asks for a digest. That is right for the person and wrong for the account, because the question
 * a manager actually has is «does the analyst on this client see the budget alerts?» — and there was
 * no way to answer it, let alone to arrange it.
 *
 * ## A row here is a REQUEST, never a grant
 *
 * This is the property the whole unit turns on, and the schema is deliberately unable to express
 * anything stronger. A row says «tell this person about this project», and it is resolved at send
 * time against that person's own live ceiling — `DigestScope`, the same one the request path uses.
 * Somebody who cannot reach the project is dropped, whatever this table says, and whoever added them
 * is told the row is inert rather than left believing it worked.
 *
 * Two consequences worth stating, because both are behaviour somebody will otherwise call a bug:
 *
 * - Adding a recipient can never widen their access. It is not a permission, and a manager who wants
 *   a colleague to SEE a client grants that in the team screen, where it is audited as access.
 * - A row survives a revocation. The person stops receiving mail the moment their membership
 *   narrows, and starts again if it widens back — because the check is at send time, not at write
 *   time. Deleting the row on revocation would silently lose the manager's intent.
 *
 * ## `project_id` and `category` are both nullable
 *
 * NULL means «all of them» in each case, which is what a manager means by «tell Sara about
 * everything». They are part of the unique key, and PostgreSQL does not collide NULLs in a unique
 * index — so the same person can hold both a blanket row and a narrower one without either being
 * refused. That is harmless: the resolver takes the union and then intersects with the ceiling.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            // NULL = every project this person may reach in the tenant.
            $table->uuid('project_id')->nullable();
            $table->unsignedBigInteger('user_id');
            // NULL = every category. Otherwise one of `NotificationPreferenceController::CATEGORIES`.
            $table->string('category', 32)->nullable();

            /*
             * Who asked for this, kept for the audit question that always follows: «why is the client
             * receiving budget alerts?» A recipient list with no author is a list nobody will touch.
             */
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'project_id', 'user_id', 'category'], 'notification_recipients_unique');
            $table->index(['tenant_id', 'project_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};
