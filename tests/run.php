<?php
declare(strict_types=1);

// Testes das funções puras e dos objetos de valor. Sem dependências: php tests/run.php
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
use function App\Models\normalize_server;
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
        echo "FALHOU: $name\n  esperado: " . var_export($expected, true) . "\n  obtido:   " . var_export($actual, true) . "\n";
    }
}

$unwrap = static fn (Result $r): mixed => $r->fold(static fn ($e) => "ERR:$e", static fn ($v) => $v);

// --- Result
$r = Result::ok(2)->map(fn (int $x): int => $x * 3)->flatMap(fn (int $x): Result => Result::ok($x + 1));
check('Result encadeia sucesso', $unwrap($r), 7);
$r = Result::ok(1)->flatMap(fn (): Result => Result::err('falhou'))->map(fn () => 'nunca');
check('Result curto-circuita na falha', $unwrap($r), 'ERR:falhou');

// --- Funções de apoio
check('pipe', pipe('trim', 'strtoupper', fn (string $s): string => $s . '!')('  oi '), 'OI!');
check('partial', partial(fn (int $a, int $b): int => $a - $b, 10)(3), 7);
check('take limita', iterator_to_array(take([1, 2, 3, 4], 2), false), [1, 2]);
check('take preguiçoso', iterator_to_array(take((function () { yield 1; yield 2; throw new Exception('lido demais'); })(), 2), false), [1, 2]);
check('take zero', iterator_to_array(take([1, 2], 0), false), []);
$mw = fn (string $tag): Closure => fn (string $req, callable $next): string => $next("$req>$tag");
check('pipeline ordem externo->interno', pipeline([$mw('a'), $mw('b')], fn (string $req): string => "$req|core")('r'), 'r>a>b|core');

// --- normalize_server
check('server simples', $unwrap(normalize_server('localhost')), 'localhost');
check('server host:porta vira vírgula', $unwrap(normalize_server('CCTDCADNT231:50100')), 'CCTDCADNT231,50100');
check('server instância', $unwrap(normalize_server('host\\SQLEXPRESS')), 'host\\SQLEXPRESS');
check('server injeção de DSN', $unwrap(normalize_server('host;Database=x')), 'ERR:Nome de servidor inválido.');
check('server vazio', $unwrap(normalize_server('  ')), 'ERR:Nome de servidor inválido.');

// --- ensure_server_allowed
check('allowlist vazia permite', $unwrap(ensure_server_allowed([], 'x')), 'x');
check('allowlist normaliza', $unwrap(ensure_server_allowed(['Host:1433'], 'host,1433')), 'host,1433');
check('allowlist bloqueia', $unwrap(ensure_server_allowed(['a'], 'b')), 'ERR:Servidor não permitido.');

// --- Connection::fromLogin
$ok = Connection::fromLogin(['server' => 'h:1', 'user' => 'sa', 'pass' => 'p'], [], true);
check('login válido', $ok->fold(fn () => null, fn (Connection $c): array => [$c->server, $c->user, $c->display]), ['h,1', 'sa', 'h:1']);
check('login sem usuário', $unwrap(Connection::fromLogin(['server' => 'h', 'user' => ' '], [], true)), 'ERR:Informe o usuário.');
check('login servidor inválido', $unwrap(Connection::fromLogin(['server' => 'a b', 'user' => 'u'], [], true)), 'ERR:Nome de servidor inválido.');
$c = new Connection('s', 'u', 'p', true, 's');
check('Connection ida e volta', Connection::fromArray($c->withDatabase('db')->toArray())->database, 'db');
check('Connection imutável', $c->database, '');

// --- split_batches
check('GO separa lotes', split_batches("SELECT 1\nGO\nSELECT 2"), ['SELECT 1', 'SELECT 2']);
check('go minúsculo e com contagem', split_batches("a\n  go 5\nb"), ['a', 'b']);
check('GO dentro de texto não separa', split_batches("SELECT 'GO'\nFROM GOLD"), ["SELECT 'GO'\nFROM GOLD"]);
check('lotes vazios descartados', split_batches("GO\n\nGO"), []);

// --- demais funções puras
check('quote_identifier', quote_identifier('a]b'), '[a]]b]');
check('effective_limit sem pedido', effective_limit(0, 100), 100);
check('effective_limit respeita teto', effective_limit(500, 100), 100);
check('effective_limit menor que teto', effective_limit(10, 100), 10);
check('type_label nvarchar', type_label('nvarchar', 100, 0, 0), 'nvarchar(50)');
check('type_label nvarchar max', type_label('nvarchar', -1, 0, 0), 'nvarchar(max)');
check('type_label decimal', type_label('decimal', 9, 18, 2), 'decimal(18,2)');
check('type_label int', type_label('INT', 4, 10, 0), 'int');
check('database_view sistema', database_view(['name' => 'master', 'is_system' => 1]), ['name' => 'master', 'system' => true]);
check('database_view usuário', database_view(['name' => 'App', 'is_system' => '0']), ['name' => 'App', 'system' => false]);
check('safe_cell binário', safe_cell("\x01\xff"), '0x01FF');
check('safe_cell texto', safe_cell('João'), 'João');
check('safe_cell null', safe_cell(null), null);
check('clean_error_message', clean_error_message("[Microsoft][ODBC Driver 18 for SQL Server][SQL Server]Invalid object name 'x'."), "Invalid object name 'x'.");
check('build_dsn mínimo', build_dsn('h', false, 10, null), 'sqlsrv:Server=h;LoginTimeout=10;APP=SQL Web Manager');
check('build_dsn completo', build_dsn('h', true, 5, true), 'sqlsrv:Server=h;LoginTimeout=5;APP=SQL Web Manager;TrustServerCertificate=1;Encrypt=1');

// --- DDL
$idxRows = [
    ['index_id' => 1, 'name' => 'PK_t', 'type_desc' => 'CLUSTERED', 'is_unique' => 1, 'is_primary_key' => 1, 'is_unique_constraint' => 0, 'filter_definition' => null, 'is_descending_key' => 0, 'is_included_column' => 0, 'column_name' => 'id'],
    ['index_id' => 2, 'name' => 'IX_t_nome', 'type_desc' => 'NONCLUSTERED', 'is_unique' => 0, 'is_primary_key' => 0, 'is_unique_constraint' => 0, 'filter_definition' => '([nome] IS NOT NULL)', 'is_descending_key' => 1, 'is_included_column' => 0, 'column_name' => 'nome'],
    ['index_id' => 2, 'name' => 'IX_t_nome', 'type_desc' => 'NONCLUSTERED', 'is_unique' => 0, 'is_primary_key' => 0, 'is_unique_constraint' => 0, 'filter_definition' => '([nome] IS NOT NULL)', 'is_descending_key' => 0, 'is_included_column' => 1, 'column_name' => 'ativo'],
];
$indexes = group_indexes($idxRows);
check('group_indexes agrupa por índice', count($indexes), 2);
check('group_indexes chaves e incluídas', [$indexes[1]['keys'], $indexes[1]['included']], [[['name' => 'nome', 'desc' => true]], ['ativo']]);

$fkRows = [
    ['fk_id' => 10, 'name' => 'FK_t_o', 'on_delete' => 'CASCADE', 'on_update' => 'NO_ACTION', 'parent_schema' => 'dbo', 'parent_table' => 't', 'column_name' => 'org_id', 'ref_schema' => 'dbo', 'ref_table' => 'org', 'ref_column' => 'id'],
    ['fk_id' => 11, 'name' => 'FK_x_t', 'on_delete' => 'SET_NULL', 'on_update' => 'NO_ACTION', 'parent_schema' => 'dbo', 'parent_table' => 'x', 'column_name' => 't_id', 'ref_schema' => 'dbo', 'ref_table' => 't', 'ref_column' => 'id'],
];
$fks = group_foreign_keys($fkRows, 'dbo', 'T');
check('FK direção out', $fks[0]['direction'], 'out');
check('FK direção in', $fks[1]['direction'], 'in');
check('FK ação normalizada', $fks[1]['onDelete'], 'SET NULL');

$cols = [
    ['name' => 'id', 'type' => 'int', 'max_length' => 4, 'precision' => 10, 'scale' => 0, 'is_nullable' => 0, 'is_identity' => 1, 'is_computed' => 0, 'seed_value' => 1, 'increment_value' => 1, 'computed_def' => null, 'is_persisted' => null, 'default_name' => null, 'default_def' => null],
    ['name' => 'nome', 'type' => 'nvarchar', 'max_length' => 160, 'precision' => 0, 'scale' => 0, 'is_nullable' => 1, 'is_identity' => 0, 'is_computed' => 0, 'seed_value' => null, 'increment_value' => null, 'computed_def' => null, 'is_persisted' => null, 'default_name' => 'DF_nome', 'default_def' => "('x')"],
    ['name' => 'dobro', 'type' => 'int', 'max_length' => 4, 'precision' => 10, 'scale' => 0, 'is_nullable' => 1, 'is_identity' => 0, 'is_computed' => 1, 'seed_value' => null, 'increment_value' => null, 'computed_def' => '([id]*(2))', 'is_persisted' => 1, 'default_name' => null, 'default_def' => null],
];
$ddl = build_create_table('dbo', 't', $cols, $indexes, $fks, [['name' => 'CK_id', 'definition' => '([id]>(0))']]);
check('DDL colunas, PK e CHECK', str_contains($ddl, "CREATE TABLE [dbo].[t] (\n    [id] int IDENTITY(1,1) NOT NULL,\n    [nome] nvarchar(80) NULL CONSTRAINT [DF_nome] DEFAULT ('x'),\n    [dobro] AS ([id]*(2)) PERSISTED,\n    CONSTRAINT [PK_t] PRIMARY KEY CLUSTERED ([id] ASC),\n    CONSTRAINT [CK_id] CHECK ([id]>(0))\n);"), true);
check('DDL índice com INCLUDE e filtro', str_contains($ddl, 'CREATE NONCLUSTERED INDEX [IX_t_nome] ON [dbo].[t] ([nome] DESC) INCLUDE ([ativo]) WHERE ([nome] IS NOT NULL);'), true);
check('DDL só FKs de saída', str_contains($ddl, 'ALTER TABLE [dbo].[t] ADD CONSTRAINT [FK_t_o] FOREIGN KEY ([org_id]) REFERENCES [dbo].[org] ([id]) ON DELETE CASCADE;') && !str_contains($ddl, 'FK_x_t'), true);
check('DDL termina com GO', str_ends_with($ddl, "\nGO\n"), true);

$dbDdl = build_create_database(['name' => 'App', 'collation_name' => 'Latin1_General_CI_AS', 'compatibility_level' => 160, 'recovery_model_desc' => 'FULL', 'page_verify_option_desc' => 'CHECKSUM', 'is_auto_close_on' => 0, 'is_auto_shrink_on' => 0, 'is_auto_create_stats_on' => 1, 'is_auto_update_stats_on' => 1, 'is_read_committed_snapshot_on' => 0, 'snapshot_isolation_state_desc' => 'OFF']);
check('DDL do banco', str_contains($dbDdl, "CREATE DATABASE [App]\n    COLLATE Latin1_General_CI_AS;") && str_contains($dbDdl, 'ALTER DATABASE [App] SET COMPATIBILITY_LEVEL = 160;') && str_contains($dbDdl, 'AUTO_SHRINK OFF'), true);

check('space_view', space_view(['row_count' => '10', 'reserved_kb' => 800, 'used_kb' => 600, 'data_kb' => 400]), ['rows' => 10, 'reservedKb' => 800, 'dataKb' => 400, 'indexKb' => 200, 'unusedKb' => 200]);
check('space_view vazio (view)', space_view(['row_count' => null, 'reserved_kb' => null, 'used_kb' => null, 'data_kb' => null])['rows'], null);

// --- Config::fromEnv
check('fromEnv tipado', Config::fromEnv(['SQLWM_MAX_ROWS' => '500', 'SQLWM_ENCRYPT' => 'true', 'SQLWM_ALLOWED_SERVERS' => 'a, b ,,c', 'SQLWM_DEFAULT_SERVER' => ' h,1433 ']), ['default_server' => 'h,1433', 'allowed_servers' => ['a', 'b', 'c'], 'encrypt' => true, 'max_rows' => 500]);
check('fromEnv ignora vazio e inválido', Config::fromEnv(['SQLWM_MAX_ROWS' => 'abc', 'SQLWM_ENCRYPT' => '', 'OUTRA' => 'x']), []);
check('fromEnv bool falso', Config::fromEnv(['SQLWM_TRUST_SERVER_CERTIFICATE' => 'false']), ['trust_server_certificate' => false]);

echo $failures === 0 ? "OK: $total verificações passaram.\n" : "$failures de $total verificações falharam.\n";
exit($failures === 0 ? 0 : 1);
