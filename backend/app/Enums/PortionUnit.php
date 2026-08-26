<?php

namespace App\Enums;

/**
 * The portion units NutriLens accepts. Constraining the set keeps the AI from
 * inventing units, lets the frontend render a picker, and makes portion scaling
 * predictable.
 *
 * This is a plain list rather than a backed enum on the column: users may type
 * their own unit for a manual item, and we do not want a migration every time
 * someone logs "handful".
 */
enum PortionUnit: string
{
    case Gram = 'g';
    case Milliliter = 'ml';
    case Ounce = 'oz';
    case FluidOunce = 'fl oz';
    case Cup = 'cup';
    case Tablespoon = 'tbsp';
    case Teaspoon = 'tsp';
    case Slice = 'slice';
    case Piece = 'piece';
    case Serving = 'serving';
    case Bowl = 'bowl';
    case Plate = 'plate';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
