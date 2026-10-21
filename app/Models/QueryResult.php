<?php
declare(strict_types=1);

namespace App\Models;

/** Resultado imutável da execução de um script. */
final readonly class QueryResult
{
    /**
     * @param list<array{columns:list<array>,rows:list<array>,truncated:bool}> $sets
     * @param list<string> $messages
     * @param ?array{message:string,number:?int,state:?string} $error
     * @param ?array{page:int,size:int,hasMore:bool} $page presente quando o resultado é uma página paginada no servidor
     */
    public function __construct(
        public array $sets,
        public array $messages,
        public ?array $error,
        public int $elapsedMs,
        public ?array $page = null,
    ) {
    }

    public function totalRows(): int
    {
        return array_sum(array_map(static fn (array $set): int => count($set['rows']), $this->sets));
    }

    public function toArray(): array
    {
        $messages = $this->messages;
        if ($this->sets === [] && $messages === [] && $this->error === null) {
            $messages = ['Command(s) completed successfully.'];
        }

        return [
            'ok'         => $this->error === null,
            'error'      => $this->error,
            'sets'       => $this->sets,
            'messages'   => $messages,
            'total_rows' => $this->totalRows(),
            'elapsed_ms' => $this->elapsedMs,
            'page'       => $this->page,
        ];
    }
}
