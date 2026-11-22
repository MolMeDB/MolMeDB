<?php

namespace Database\Factories;

use App\Models\ApplicationError;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<ApplicationError>
 */
class ApplicationErrorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $message = fake()->sentence();

        return [
            'day' => now()->toDateString(),
            'fingerprint' => sha1(RuntimeException::class.'|'.$message),
            'exception' => RuntimeException::class,
            'message' => $message,
            'location' => 'app/Services/Example.php:'.fake()->numberBetween(10, 400),
            'context' => 'GET api/example',
            'occurrences' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function seenOn(string $day, int $occurrences = 1): static
    {
        return $this->state(fn (): array => [
            'day' => $day,
            'occurrences' => $occurrences,
            'first_seen_at' => $day.' 08:00:00',
            'last_seen_at' => $day.' 20:00:00',
        ]);
    }
}
