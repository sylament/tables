<?php

declare(strict_types=1);

namespace Sylament\Tables\Tests\Fixtures\Records;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

// Public so `Container::has()` on the real container sees it: the `Invoker` holds
// `service_container`, which resolves public ids only.
#[Autoconfigure(public: true)]
final readonly class SongRepository
{
    /**
     * @var list<Song>
     */
    private array $songs;

    public function __construct()
    {
        $this->songs = [
            new Song('Airbag', 'OK Computer', id: 1),
            new Song('Let Down', 'OK Computer', id: 2),
        ];
    }

    /**
     * @return list<Song>
     */
    public function all(): array
    {
        return $this->songs;
    }
}
