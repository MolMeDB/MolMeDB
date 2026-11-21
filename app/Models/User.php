<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Enums\PermissionEnums;
use App\Enums\RoleEnums;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;
use Spatie\Activitylog\Traits\CausesActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAvatar, HasName, MustVerifyEmail
{
    use CausesActivity, HasRoles;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'affiliation',
        'is_ghost',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $connection;

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->connection = config('database.default');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasPermissionTo(PermissionEnums::ADMIN_PANEL->value);
    }

    public function hasAdminRole(): bool
    {
        return $this->hasRole(RoleEnums::ADMIN->value);
    }

    /**
     * Whether the user may give these permissions to a role: administrators
     * any, everyone else only permissions they hold, so that managing roles
     * never raises anyone's rights.
     *
     * @param  iterable<int, Permission>  $permissions
     */
    public function canGrantPermissions(iterable $permissions): bool
    {
        if ($this->hasAdminRole()) {
            return true;
        }

        $held = $this->getAllPermissions()->pluck('id')->all();

        foreach ($permissions as $permission) {
            if (! in_array($permission->getKey(), $held, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the user may assign the role to someone: only administrators
     * hand out the Administrator role, and nobody a role whose permissions
     * they do not hold.
     */
    public function canAssignRole(Role $role): bool
    {
        if ($this->hasAdminRole()) {
            return true;
        }

        return $role->name !== RoleEnums::ADMIN->value
            && $this->canGrantPermissions($role->permissions);
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function getFilamentAvatarUrl(): ?string
    {
        if (! $this->name) {
            return null;
        }

        return 'https://ui-avatars.com/api/?name='.urlencode($this->name);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            // Set default role for each new user
            $user->assignRole(Role::where('name', RoleEnums::VIEWER->value)->first());
        });
    }

    /**
     * Route notifications for the mail channel.
     *
     * @return array<string, string>|string
     */
    public function routeNotificationForMail(Notification $notification): array|string
    {
        // Return email address only...
        return $this->email;

        // Return email address and name...
        return [$this->email => $this->name];
    }

    /**
     * Returns identifiers added by current user
     */
    public function identifiers(): MorphMany
    {
        return $this->morphMany(Identifier::class, 'source');
    }

    public function name(): ?string
    {
        return $this->name;
    }

    public function getPrettyNameAttribute(): ?string
    {
        return $this->name." ($this->email)";
    }

    public function logs(): MorphMany
    {
        return $this->actions();
    }

    public function feedbackSubmissions(): HasMany
    {
        return $this->hasMany(FeedbackSubmission::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }
}
