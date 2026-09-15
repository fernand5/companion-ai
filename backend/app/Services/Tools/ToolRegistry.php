<?php

namespace App\Services\Tools;

use App\Models\User;

class ToolRegistry
{
    /** @var array<string, AiTool> */
    private array $tools = [];

    /**
     * @param  AiTool[]  $tools
     */
    public function __construct(array $tools = [])
    {
        foreach ($tools as $tool) {
            $this->register($tool);
        }
    }

    public function register(AiTool $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    public function get(string $name): ?AiTool
    {
        return $this->tools[$name] ?? null;
    }

    /**
     * @return AiTool[]
     */
    public function all(): array
    {
        return array_values($this->tools);
    }

    /**
     * Provider-agnostic tool declarations for AiProvider::chat().
     *
     * @return array<int, array{name: string, description: string, parameters: array}>
     */
    public function declarations(): array
    {
        return array_map(fn (AiTool $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->schema(),
        ], $this->all());
    }

    /**
     * Execute a tool by name, always scoped to $user. Returns a JSON-serializable
     * result, or an ['error' => string] payload if the tool doesn't exist or the
     * arguments are invalid — the model sees this and can adjust rather than the
     * request failing outright.
     */
    public function call(string $name, array $arguments, User $user): mixed
    {
        $tool = $this->get($name);

        if (! $tool) {
            return ['error' => "Unknown tool: {$name}"];
        }

        try {
            return $tool->execute($arguments, $user);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
