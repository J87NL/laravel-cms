<?php

declare(strict_types=1);

namespace J87NL\Cms\Blocks;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class BlockRegistry
{
    /**
     * @var array<string, BlockType>
     */
    private array $types = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  BlockType|class-string<BlockType>  $type
     */
    public function register(BlockType|string $type): static
    {
        $instance = is_string($type) ? $this->container->make($type) : $type;

        if (! $instance instanceof BlockType) {
            throw new InvalidArgumentException(sprintf('[%s] does not implement %s.', get_debug_type($instance), BlockType::class));
        }

        $this->types[$instance->key()] = $instance;

        return $this;
    }

    /**
     * @return array<string, BlockType>
     */
    public function registered(): array
    {
        return $this->types;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function get(string $key): ?BlockType
    {
        return $this->all()[$key] ?? null;
    }

    public function find(string $key): BlockType
    {
        $type = $this->get($key);

        if (is_null($type)) {
            throw new InvalidArgumentException(sprintf('The block type [%s] is not registered or is disabled.', $key));
        }

        return $type;
    }

    /**
     * @return array<string, BlockType>
     */
    public function all(): array
    {
        $disabled = config('cms.blocks.disabled', []);

        if (! is_array($disabled) || $disabled === []) {
            return $this->types;
        }

        return array_diff_key($this->types, array_flip(array_filter($disabled, 'is_string')));
    }
}
