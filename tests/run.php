<?php
declare(strict_types=1);

// Tests for the pure functions and the value objects. No dependencies: php tests/run.php
require __DIR__ . '/../app/bootstrap.php';

use App\Core\Config;
use App\Models\Connection;
use App\Support\Result;

use function App\Models\build_create_database;
use function App\Models\build_create_table;
use function App\Models\build_dsn;
use function App\Models\clean_error_message;
use function App\Models\database_view;
use function App\Models\effective_limit;
use function App\Models\ensure_server_allowed;
use function App\Models\group_foreign_keys;
use function App\Models\group_indexes;
use function App\Models\build_import_create_table;
use function App\Models\build_insert_sql;
use function App\Models\csv_line;
use function App\Models\import_column_type;
use function App\Models\insert_batch_size;
use function App\Models\format_decimal;
use function App\Models\is_decimal_type;
use function App\Models\csv_separator;
use function App\Models\mask_sql;
use function App\Models\normalize_server;
use function App\Models\paginate_sql;
use function App\Models\safe_filename;
use function App\Models\unique_keys;
use function App\Models\quote_identifier;
use function App\Models\safe_cell;
use function App\Models\space_view;
use function App\Models\split_batches;
use function App\Models\type_label;
use function App\Support\partial;
use function App\Support\pipe;
use function App\Support\pipeline;
use function App\Support\take;

$failures = 0;
$total = 0;

function check(string $name, mixed $actual, mixed $expected): void
{
    global $failures, $total;
    $total++;
    if ($actual !== $expected) {
        $failures++;
        echo "FAILED: $name\n  expected: " . var_export($expected, true) . "\n  got:      " . var_export($actual, true) . "\n";
    }
}

$unwrap = static fn (Result $r): mixed => $r->fold(static fn ($e) => "ERR:$e", static fn ($v) => $v);

// --- Result
$r = Result::ok(2)->map(fn (int $x): int => $x * 3)->flatMap(fn (int $x): Result => Result::ok($x + 1));
check('Result chains success', $unwrap($r), 7);
$r = Result::ok(1)->flatMap(fn (): Result => Result::err('falhou'))->map(fn () => 'nunca');
check('Result short-circuits on failure', $unwrap($r), 'ERR:falhou');

// --- Helper functions
check('pipe', pipe('trim', 'strtoupper', fn (string $s): string => $s . '!')('  oi '), 'OI!');
check('partial', partial(fn (int $a, int $b): int => $a - $b, 10)(3), 7);
check('take limits', iterator_to_array(take([1, 2, 3, 4], 2), false), [1, 2]);
check('take is lazy', iterator_to_array(take((function () { yield 1; yield 2; throw new Exception('lido demais'); })(), 2), false), [1, 2]);
check('take zero', iterator_to_array(take([1, 2], 0), false), []);
$mw = fn (string $tag): Closure => fn (string $req, callable $next): string => $next("$req>$tag");
check('pipeline order outer->inner', pipeline([$mw('a'), $mw('b')], fn (string $req): string => "$req|core")('r'), 'r>a>b|core');

// --- normalize_server
check('server plain', $unwrap(normalize_server('localhost')), 'localhost');
check('server host:port becomes a comma', $unwrap(normalize_server('CCTDCADNT231:50100')), 'CCTDCADNT231,50100');
check('server instance', $unwrap(normalize_server('host\\SQLEXPRESS')), 'host\\SQLEXPRESS');
check('server DSN injection', $unwrap(normalize_server('host;Database=x')), 'ERR:Invalid server name.');
check('server empty', $unwrap(normalize_server('  ')), 'ERR:Invalid server name.');

// --- ensure_server_allowed
check('empty allowlist allows', $unwrap(ensure_server_allowed([], 'x')), 'x');
check('allowlist normalizes', $unwrap(ensure_server_allowed(['Host:1433'], 'host,1433')), 'host,1433');
check('allowlist blocks', $unwrap(ensure_server_allowed(['a'], 'b')), 'ERR:Server not allowed.');

// --- Connection::fromLogin
$ok = Connection::fromLogin(['server' => 'h:1', 'user' => 'sa', 'pass' => 'p'], [], true);
check('valid login', $ok->fold(fn () => null, fn (Connection $c): array => [$c->server, $c->user, $c->display]), ['h,1', 'sa', 'h:1']);
check('login without user', $unwrap(Connection::fromLogin(['server' => 'h', 'user' => ' '], [], true)), 'ERR:Enter the user name.');
check('login invalid server', $unwrap(Connection::fromLogin(['server' => 'a b', 'user' => 'u'], [], true)), 'ERR:Invalid server name.');
$c = new Connection('s', 'u', 'p', true, 's');
check('Connection round trip', Connection::fromArray($c->withDatabase('db')->toArray())->database, 'db');
check('Connection is immutable', $c->database, '');

// --- split_batches
check('GO splits batches', split_batches("SELECT 1\nGO\nSELECT 2"), ['SELECT 1', 'SELECT 2']);
check('lower-case go with a count', split_batches("a\n  go 5\nb"), ['a', 'b']);
check('GO inside text does not split', split_batches("SELECT 'GO'\nFROM GOLD"), ["SELECT 'GO'\nFROM GOLD"]);
check('empty batches discarded', split_batches("GO\n\nGO"), []);

// --- remaining pure functions
check('quote_identifier', quote_identifier('a]b'), '[a]]b]');
check('effective_limit with no request', effective_limit(0, 100), 100);
check('effective_limit respects the cap', effective_limit(500, 100), 100);
check('effective_limit below the cap', effective_limit(10, 100), 10);
check('type_label nvarchar', type_label('nvarchar', 100, 0, 0), 'nvarchar(50)');
check('type_label nvarchar max', type_label('nvarchar', -1, 0, 0), 'nvarchar(max)');
check('type_label decimal', type_label('decimal', 9, 18, 2), 'decimal(18,2)');
check('type_label int', type_label('INT', 4, 10, 0), 'int');
check('database_view system', database_view(['name' => 'master', 'is_system' => 1]), ['name' => 'master', 'system' => true]);
check('database_view user', database_view(['name' => 'App', 'is_system' => '0']), ['name' => 'App', 'system' => false]);
check('safe_cell binary', safe_cell("\x01\xff"), '0x01FF');
check('safe_cell text', safe_cell('João'), 'João');
check('safe_cell null', safe_cell(null), null);
check('clean_error_message', clean_error_message("[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Invalid object name 'x'."), "Invalid object name 'x'.");
check('build_dsn minimal', build_dsn('h', false, 10, null), 'sqlsrv:Server=h;LoginTimeout=10;APP=SQL Web Manager');
check('build_dsn full', build_dsn('h', true, 5, true), 'sqlsrv:Server=h;LoginTimeout=5;APP=SQL Web Manager;TrustServerCertificate=1;Encrypt=1');

// --- DDL
$idxRows = [
    ['index_id' => 1, 'name' => 'PK_t', 'type_desc' => 'CLUSTERED', 'is_unique' => 1, 'is_primary_key' => 1, 'is_unique_constraint' => 0, 'filter_definition' => null, 'is_descending_key' => 0, 'is_included_column' => 0, 'column_name' => 'id'],
    ['index_id' => 2, 'name' => 'IX_t_nome', 'type_desc' => 'NONCLUSTERED', 'is_unique' => 0, 'is_primary_key' => 0, 'is_unique_constraint' => 0, 'filter_definition' => '([nome] IS NOT NULL)', 'is_descending_key' => 1, 'is_included_column' => 0, 'column_name' => 'nome'],
    ['index_id' => 2, 'name' => 'IX_t_nome', 'type_desc' => 'NONCLUSTERED', 'is_unique' => 0, 'is_primary_key' => 0, 'is_unique_constraint' => 0, 'filter_definition' => '([nome] IS NOT NULL)', 'is_descending_key' => 0, 'is_included_column' => 1, 'column_name' => 'ativo'],
];
$indexes = group_indexes($idxRows);
check('group_indexes groups by index', count($indexes), 2);
check('group_indexes keys and included', [$indexes[1]['keys'], $indexes[1]['included']], [[['name' => 'nome', 'desc' => true]], ['ativo']]);

$fkRows = [
    ['fk_id' => 10, 'name' => 'FK_t_o', 'on_delete' => 'CASCADE', 'on_update' => 'NO_ACTION', 'parent_schema' => 'dbo', 'parent_table' => 't', 'column_name' => 'org_id', 'ref_schema' => 'dbo', 'ref_table' => 'org', 'ref_column' => 'id'],
    ['fk_id' => 11, 'name' => 'FK_x_t', 'on_delete' => 'SET_NULL', 'on_update' => 'NO_ACTION', 'parent_schema' => 'dbo', 'parent_table' => 'x', 'column_name' => 't_id', 'ref_schema' => 'dbo', 'ref_table' => 't', 'ref_column' => 'id'],
];
$fks = group_foreign_keys($fkRows, 'dbo', 'T');
check('FK direction out', $fks[0]['direction'], 'out');
check('FK direction in', $fks[1]['direction'], 'in');
check('FK action normalized', $fks[1]['onDelete'], 'SET NULL');

$cols = [
    ['name' => 'id', 'type' => 'int', 'max_length' => 4, 'precision' => 10, 'scale' => 0, 'is_nullable' => 0, 'is_identity' => 1, 'is_computed' => 0, 'seed_value' => 1, 'increment_value' => 1, 'computed_def' => null, 'is_persisted' => null, 'default_name' => null, 'default_def' => null],
    ['name' => 'nome', 'type' => 'nvarchar', 'max_length' => 160, 'precision' => 0, 'scale' => 0, 'is_nullable' => 1, 'is_identity' => 0, 'is_computed' => 0, 'seed_value' => null, 'increment_value' => null, 'computed_def' => null, 'is_persisted' => null, 'default_name' => 'DF_nome', 'default_def' => "('x')"],
    ['name' => 'dobro', 'type' => 'int', 'max_length' => 4, 'precision' => 10, 'scale' => 0, 'is_nullable' => 1, 'is_identity' => 0, 'is_computed' => 1, 'seed_value' => null, 'increment_value' => null, 'computed_def' => '([id]*(2))', 'is_persisted' => 1, 'default_name' => null, 'default_def' => null],
];
$ddl = build_create_table('dbo', 't', $cols, $indexes, $fks, [['name' => 'CK_id', 'definition' => '([id]>(0))']]);
check('DDL columns, PK and CHECK', str_contains($ddl, "CREATE TABLE [dbo].[t] (\n    [id] int IDENTITY(1,1) NOT NULL,\n    [nome] nvarchar(80) NULL CONSTRAINT [DF_nome] DEFAULT ('x'),\n    [dobro] AS ([id]*(2)) PERSISTED,\n    CONSTRAINT [PK_t] PRIMARY KEY CLUSTERED ([id] ASC),\n    CONSTRAINT [CK_id] CHECK ([id]>(0))\n);"), true);
check('DDL index with INCLUDE and filter', str_contains($ddl, 'CREATE NONCLUSTERED INDEX [IX_t_nome] ON [dbo].[t] ([nome] DESC) INCLUDE ([ativo]) WHERE ([nome] IS NOT NULL);'), true);
check('DDL only outgoing FKs', str_contains($ddl, 'ALTER TABLE [dbo].[t] ADD CONSTRAINT [FK_t_o] FOREIGN KEY ([org_id]) REFERENCES [dbo].[org] ([id]) ON DELETE CASCADE;') && !str_contains($ddl, 'FK_x_t'), true);
check('DDL ends with GO', str_ends_with($ddl, "\nGO\n"), true);

$dbDdl = build_create_database(['name' => 'App', 'collation_name' => 'Latin1_General_CI_AS', 'compatibility_level' => 160, 'recovery_model_desc' => 'FULL', 'page_verify_option_desc' => 'CHECKSUM', 'is_auto_close_on' => 0, 'is_auto_shrink_on' => 0, 'is_auto_create_stats_on' => 1, 'is_auto_update_stats_on' => 1, 'is_read_committed_snapshot_on' => 0, 'snapshot_isolation_state_desc' => 'OFF']);
check('database DDL', str_contains($dbDdl, "CREATE DATABASE [App]\n    COLLATE Latin1_General_CI_AS;") && str_contains($dbDdl, 'ALTER DATABASE [App] SET COMPATIBILITY_LEVEL = 160;') && str_contains($dbDdl, 'AUTO_SHRINK OFF'), true);

check('space_view', space_view(['row_count' => '10', 'reserved_kb' => 800, 'used_kb' => 600, 'data_kb' => 400]), ['rows' => 10, 'reservedKb' => 800, 'dataKb' => 400, 'indexKb' => 200, 'unusedKb' => 200]);
check('space_view empty (view)', space_view(['row_count' => null, 'reserved_kb' => null, 'used_kb' => null, 'data_kb' => null])['rows'], null);

// --- Config::fromEnv
check('fromEnv typed', Config::fromEnv(['SQLWM_MAX_ROWS' => '500', 'SQLWM_ENCRYPT' => 'true', 'SQLWM_ALLOWED_SERVERS' => 'a, b ,,c', 'SQLWM_DEFAULT_SERVER' => ' h,1433 ']), ['default_server' => 'h,1433', 'allowed_servers' => ['a', 'b', 'c'], 'encrypt' => true, 'max_rows' => 500]);
check('fromEnv ignores empty and invalid', Config::fromEnv(['SQLWM_MAX_ROWS' => 'abc', 'SQLWM_ENCRYPT' => '', 'OUTRA' => 'x']), []);
check('fromEnv false bool', Config::fromEnv(['SQLWM_TRUST_SERVER_CERTIFICATE' => 'false']), ['trust_server_certificate' => false]);


// --- Server-side pagination
$page = static fn (string $sql): ?string => paginate_sql($sql, 200, 201);
check('paginate without ORDER BY', $page('SELECT * FROM t;'), 'SELECT * FROM t ORDER BY (SELECT NULL) OFFSET 200 ROWS FETCH NEXT 201 ROWS ONLY');
check('paginate with ORDER BY', $page("SELECT a FROM t ORDER BY a DESC\n"), "SELECT a FROM t ORDER BY a DESC OFFSET 200 ROWS FETCH NEXT 201 ROWS ONLY");
check('paginate ignores a trailing comment', $page("SELECT a FROM t -- fim\n"), 'SELECT a FROM t ORDER BY (SELECT NULL) OFFSET 200 ROWS FETCH NEXT 201 ROWS ONLY');
check('paginate CTE', str_ends_with((string) $page('WITH x AS (SELECT 1 a ORDER BY a) SELECT * FROM x'), 'ORDER BY (SELECT NULL) OFFSET 200 ROWS FETCH NEXT 201 ROWS ONLY'), true);
check('paginate ORDER BY inside OVER does not count', str_contains((string) $page('SELECT ROW_NUMBER() OVER (ORDER BY a) r FROM t'), 'ORDER BY (SELECT NULL)'), true);
check('paginate text containing ORDER BY does not count', str_contains((string) $page("SELECT 'order by x' FROM t"), 'ORDER BY (SELECT NULL)'), true);
check('paginate rejects TOP', $page('SELECT TOP 10 * FROM t'), null);
check('paginate accepts TOP in a subquery', $page('SELECT * FROM (SELECT TOP 5 a FROM t ORDER BY a) q') !== null, true);
check('paginate rejects INTO', $page('SELECT * INTO #x FROM t'), null);
check('paginate rejects several statements', $page('SELECT 1; SELECT 2'), null);
check('paginate rejects INSERT', $page('INSERT t VALUES (1)'), null);
check('paginate rejects EXEC', $page('EXEC sp_who'), null);
check('paginate rejects variable assignment', $page('SELECT @a = 1'), null);
check('paginate rejects UNION without ORDER BY', $page('SELECT 1 UNION SELECT 2'), null);
check('paginate rejects DISTINCT without ORDER BY', $page('SELECT DISTINCT a FROM t'), null);
check('paginate rejects FOR JSON', $page('SELECT a FROM t FOR JSON AUTO'), null);
check('paginate rejects empty input', $page('  ; '), null);
check('mask_sql keeps the length', strlen(mask_sql("SELECT 'a' /* x */ , [b c] -- z\nFROM (SELECT 1) q")), strlen("SELECT 'a' /* x */ , [b c] -- z\nFROM (SELECT 1) q"));

// --- Streaming export
check('csv_line quotes and nulls', csv_line(['a;b', null, 'x"y', 3, "l1\nl2", 'ok']), '"a;b";;"x""y";3;"l1' . "\n" . 'l2";ok');
check('csv_line with comma', csv_line(['a,b', 'c;d', null], ','), '"a,b",c;d,');
check('csv_line with tab', csv_line(['a b', "c\td"], "\t"), "a b\t\"c\td\"");
check('csv_separator valid', [csv_separator(';'), csv_separator(','), csv_separator('|'), csv_separator('tab'), csv_separator('TAB'), csv_separator("\t")], [';', ',', '|', "\t", "\t", "\t"]);
check('csv_separator invalid falls back to the default', [csv_separator('x'), csv_separator(null), csv_separator('', ','), csv_separator(' ')], [';', ';', ',', ';']);
check('format_decimal four zero places', format_decimal('30000.0000'), '30000.00');
check('format_decimal without leading zero', format_decimal('.0000'), '0.00');
check('format_decimal negative', format_decimal('-.5000'), '-0.50');
check('format_decimal negative zero', format_decimal('-.0000'), '0.00');
check('format_decimal already two places', format_decimal('5.00'), '5.00');
check('format_decimal one place', format_decimal('0.5'), '0.50');
check('format_decimal keeps precision', [format_decimal('12.345600'), format_decimal('.0125')], ['12.3456', '0.0125']);
check('format_decimal integer and text untouched', [format_decimal('7'), format_decimal('abc'), format_decimal('1E+3'), format_decimal('')], ['7', 'abc', '1E+3', '']);
check('is_decimal_type', [is_decimal_type('money'), is_decimal_type('DECIMAL'), is_decimal_type('numeric'), is_decimal_type('smallmoney'), is_decimal_type('float'), is_decimal_type('int')], [true, true, true, true, false, false]);
check('import_column_type accepted', array_map(import_column_type(...), ['INT', 'bigint', 'nvarchar(255)', 'NVARCHAR(MAX)', 'varchar(8000)', 'decimal(38, 6)', 'datetime2', 'bit']), ['int', 'bigint', 'nvarchar(255)', 'nvarchar(max)', 'varchar(8000)', 'decimal(38,6)', 'datetime2', 'bit']);
check('import_column_type rejected', array_map(import_column_type(...), ['nvarchar(5000)', 'nvarchar(0)', 'decimal(39,2)', 'decimal(5,6)', 'int); DROP TABLE x;--', 'money', 'nvarchar', '']), [null, null, null, null, null, null, null, null]);
check('insert_batch_size', [insert_batch_size(1), insert_batch_size(3), insert_batch_size(50), insert_batch_size(1024), insert_batch_size(5000)], [1000, 666, 40, 1, 1]);
check('build_insert_sql', build_insert_sql('dbo', 'T]x', ['a', 'b]c'], 2), 'INSERT INTO [dbo].[T]]x] ([a], [b]]c]) VALUES (?, ?), (?, ?)');
check('build_import_create_table', build_import_create_table('dbo', 'novo', [['name' => 'id', 'type' => 'int'], ['name' => 'nome', 'type' => 'nvarchar(50)']]), "CREATE TABLE [dbo].[novo] (\n    [id] int NULL,\n    [nome] nvarchar(50) NULL\n)");
check('build_import_create_table rejects a type and a repeated name', [build_import_create_table('dbo', 't', [['name' => 'a', 'type' => 'xml']]), build_import_create_table('dbo', 't', [['name' => 'a', 'type' => 'int'], ['name' => 'A', 'type' => 'int']]), build_import_create_table('dbo', 't', [])], [null, null, null]);
check('unique_keys', unique_keys(['a', 'b', 'a', 'a']), ['a', 'b', 'a_2', 'a_3']);
check('safe_filename', safe_filename('Sales.Orders_21102026'), 'Sales.Orders_21102026');
check('safe_filename dangerous', safe_filename('../a b"c'), 'a_b_c');
check('safe_filename empty', safe_filename('..'), 'resultado');

echo $failures === 0 ? "OK: $total checks passed.\n" : "$failures of $total checks failed.\n";
exit($failures === 0 ? 0 : 1);
