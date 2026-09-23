<?php

declare(strict_types=1);

use App\Retrieval\Stopwords;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G3-T1: the keyword leg moves from LIKE to InnoDB FULLTEXT (03 §6).
 *
 * MySQL only; SQLite (tests) keeps the LIKE fallback in Retriever.
 *   1. fulltext_stopwords — InnoDB's user stopword table (one varchar column
 *      named `value`), seeded from App\Retrieval\Stopwords. Global, not
 *      tenant data (on the SchemaConformanceTest allowlist).
 *   2. innodb_ft_user_stopword_table is a SESSION variable, so it needs no
 *      SUPER privilege on the managed cluster: set it, then build the indexes.
 *      An index keeps the stopword list it was built with.
 *   3. ft_chunk_search on document_chunks(content, heading_path) — what the
 *      keyword leg queries — and ft_doc_search on documents is rebuilt with
 *      the same list.
 * innodb_ft_min_token_size = 2 is a server parameter (doc 04); set it on the
 * cluster before running this, or two-letter terms stay unindexed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('create table if not exists fulltext_stopwords (value varchar(30) not null default \'\') engine=InnoDB');
        DB::statement('delete from fulltext_stopwords');
        foreach (Stopwords::WORDS as $w) {
            DB::insert('insert into fulltext_stopwords (value) values (?)', [$w]);
        }
        DB::statement("set session innodb_ft_user_stopword_table = '".DB::getDatabaseName()."/fulltext_stopwords'");

        DB::statement('alter table documents drop index ft_doc_search');
        DB::statement('alter table documents add fulltext index ft_doc_search (title, body_text)');
        DB::statement('alter table document_chunks add fulltext index ft_chunk_search (content, heading_path)');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('alter table document_chunks drop index ft_chunk_search');
        DB::statement('drop table if exists fulltext_stopwords');
    }
};
