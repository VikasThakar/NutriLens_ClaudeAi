<?php

namespace App\Services\AI;

use App\Enums\PortionUnit;
use App\Services\AI\Contracts\MealVisionAnalyzer;
use App\Services\AI\Data\AnalyzedFoodItem;
use App\Services\AI\Data\AnalyzedMeal;
use App\Services\AI\Data\PreparedImage;
use App\Services\AI\Exceptions\AiResponseException;
use App\Services\AI\Exceptions\NoFoodDetectedException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Turns an uploaded photo into a validated meal analysis.
 *
 * The provider is only trusted to return *something*. Everything that leaves
 * this class has been schema-checked, range-clamped and normalised, so no
 * malformed model output can reach the client or the database.
 */
class MealAnalysisService
{
    public function __construct(
        private readonly MealVisionAnalyzer $analyzer,
        private readonly MealImagePreparer $preparer,
    ) {
    }

    /**
     * @throws \App\Services\AI\Exceptions\AiException
     */
    public function analyzeUpload(UploadedFile $file): AnalyzedMeal
    {
        $image = $this->preparer->prepare($file);

        return $this->analyze($image);
    }

    /**
     * @throws \App\Services\AI\Exceptions\AiException
     */
    public function analyze(PreparedImage $image): AnalyzedMeal
    {
        $payload = $this->analyzer->analyze($image);

        return $this->validate($payload);
    }

    public function preparer(): MealImagePreparer
    {
        return $this->preparer;
    }

    public function analyzer(): MealVisionAnalyzer
    {
        return $this->analyzer;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws \App\Services\AI\Exceptions\AiException
     */
    private function validate(array $payload): AnalyzedMeal
    {
        $maxItems = (int) config('ai.limits.max_items', 12);
        $maxCalories = (int) config('ai.limits.max_calories_per_item', 5000);
        $maxMacro = (int) config('ai.limits.max_grams_per_macro', 1000);

        $validator = Validator::make($payload, [
            'meal_name' => ['required', 'string', 'max:120'],
            'confidence' => ['required', 'numeric', 'min:0', 'max:1'],
            'notes' => ['present', 'nullable', 'string', 'max:500'],

            'items' => ['present', 'array', "max:{$maxItems}"],
            'items.*.name' => ['required', 'string', 'max:120'],
            'items.*.portion_amount' => ['required', 'numeric', 'gt:0', 'max:10000'],
            'items.*.portion_unit' => ['required', 'string', 'max:24'],
            'items.*.calories' => ['required', 'numeric', 'min:0', "max:{$maxCalories}"],
            'items.*.protein' => ['required', 'numeric', 'min:0', "max:{$maxMacro}"],
            'items.*.carbs' => ['required', 'numeric', 'min:0', "max:{$maxMacro}"],
            'items.*.fat' => ['required', 'numeric', 'min:0', "max:{$maxMacro}"],
            'items.*.confidence' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        if ($validator->fails()) {
            Log::warning('AI meal analysis failed validation', [
                'provider' => $this->analyzer->providerName(),
                'errors' => $validator->errors()->toArray(),
            ]);

            throw new AiResponseException('The AI response did not match the expected schema.');
        }

        /** @var array{meal_name:string, confidence:float|int, notes:?string, items:array<int, array<string, mixed>>} $clean */
        $clean = $validator->validated();

        // An explicitly empty analysis is a real, expected outcome — not a bug.
        if ($clean['items'] === []) {
            throw new NoFoodDetectedException($clean['notes'] ?: 'No food detected.');
        }

        $items = [];

        foreach (array_values($clean['items']) as $raw) {
            $items[] = new AnalyzedFoodItem(
                name: $this->cleanName((string) $raw['name']),
                portionAmount: round((float) $raw['portion_amount'], 2),
                portionUnit: $this->normalizeUnit((string) $raw['portion_unit']),
                calories: (int) round((float) $raw['calories']),
                protein: round((float) $raw['protein'], 1),
                carbs: round((float) $raw['carbs'], 1),
                fat: round((float) $raw['fat'], 1),
                confidence: round((float) $raw['confidence'], 3),
            );
        }

        return new AnalyzedMeal(
            mealName: $this->cleanName((string) $clean['meal_name']),
            confidence: round((float) $clean['confidence'], 3),
            items: $items,
            notes: $this->cleanNotes($clean['notes'] ?? null),
            provider: $this->analyzer->providerName(),
            model: $this->analyzer->modelName(),
        );
    }

    /**
     * Models occasionally return a unit outside the enum despite the schema
     * (or a plural, or a different casing). Map what we can, keep the rest as
     * free text — a stray unit is a cosmetic problem, not a reason to throw
     * away an otherwise good analysis.
     */
    private function normalizeUnit(string $unit): string
    {
        $normalized = Str::lower(trim($unit));

        $aliases = [
            'grams' => 'g', 'gram' => 'g', 'gr' => 'g',
            'milliliters' => 'ml', 'millilitres' => 'ml', 'milliliter' => 'ml', 'ml.' => 'ml',
            'ounces' => 'oz', 'ounce' => 'oz',
            'fluid ounce' => 'fl oz', 'fluid ounces' => 'fl oz', 'floz' => 'fl oz',
            'cups' => 'cup',
            'tablespoon' => 'tbsp', 'tablespoons' => 'tbsp', 'tbsps' => 'tbsp',
            'teaspoon' => 'tsp', 'teaspoons' => 'tsp', 'tsps' => 'tsp',
            'slices' => 'slice',
            'pieces' => 'piece', 'pcs' => 'piece', 'pc' => 'piece',
            'servings' => 'serving', 'portion' => 'serving', 'portions' => 'serving',
            'bowls' => 'bowl',
            'plates' => 'plate',
        ];

        $normalized = $aliases[$normalized] ?? $normalized;

        if (in_array($normalized, PortionUnit::values(), true)) {
            return $normalized;
        }

        return Str::limit($normalized, 24, '') ?: 'serving';
    }

    private function cleanName(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);

        return Str::limit($name, 120, '');
    }

    private function cleanNotes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? null : Str::limit($notes, 500, '');
    }
}
