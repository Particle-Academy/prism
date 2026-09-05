<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Z\Maps;

use Prism\Prism\Tool;

class ToolMap
{
    /**
     * @param  Tool[]  $tools
     * @return array<string, mixed>
     */
    public static function Map(array $tools): array
    {
        return array_map(function (Tool $tool): array {
            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => [
                        'type' => 'object',
                        'properties' => $tool->parametersAsObject(),
                        'required' => $tool->requiredParameters(),
                    ],
                ],
            ];
        }, $tools);
    }
}
