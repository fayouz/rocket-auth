<?php

namespace App\Settings;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Typed access to application-wide settings stored in the database.
 */
final class Settings
{
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        return $this->settings->find($name)?->getValue() ?? $default;
    }

    /** Persists the value; the caller flushes. */
    public function set(string $name, mixed $value): void
    {
        $setting = $this->settings->find($name);
        if (null === $setting) {
            $this->em->persist(new Setting($name, $value));
        } else {
            $setting->setValue($value);
        }
    }

    /** Removes the setting; the caller flushes. */
    public function remove(string $name): void
    {
        $setting = $this->settings->find($name);
        if (null !== $setting) {
            $this->em->remove($setting);
        }
    }

    public function isPersonalFromAllowed(): bool
    {
        return (bool) $this->get(self::PERSONAL_FROM_ALLOWED, true);
    }
}
