<?php
/**
 * DST Empire — Portfolio Analysis Engine for Entity Formation
 * Copyright (c) 2026 MNMS LLC
 * Licensed under the MIT License — see LICENSE in the project root.
 */

/**
 * IntakeRepo — CRUD layer for empire_brand_intake.
 * All reads/writes enforce tenant_id isolation.
 */
class IntakeRepo {

    /** Insert a new brand intake row; returns new primary key. */
    public static function create(int $tenantId, array $data): int {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            "INSERT INTO empire_brand_intake
             (tenant_id, brand_slug, brand_name, domain, tier, current_status, current_legal_owner,
              revenue_profile, liability_profile, decided_jurisdiction, decided_entity_type,
              decided_parent_kind, decided_trust_wrapper, decided_sale_horizon, advisor_notes_md)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $tenantId,
            $data['brand_slug'],
            $data['brand_name'],
            $data['domain'] ?? null,
            $data['tier'] ?? 'T4',
            $data['current_status'] ?? 'dba_only',
            $data['current_legal_owner'] ?? 'MNMS LLC',
            $data['revenue_profile'] ?? null,
            $data['liability_profile'] ?? 'low',
            $data['decided_jurisdiction'] ?? null,
            $data['decided_entity_type'] ?? null,
            $data['decided_parent_kind'] ?? null,
            $data['decided_trust_wrapper'] ?? 'none',
            $data['decided_sale_horizon'] ?? null,
            $data['advisor_notes_md'] ?? null,
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Fetch single intake row by id; returns null if not found or wrong tenant. */
    public static function get(int $tenantId, int $id): ?array {
        $stmt = Database::get()->prepare(
            "SELECT * FROM empire_brand_intake WHERE id=? AND tenant_id=? LIMIT 1"
        );
        $stmt->execute([$id, $tenantId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * List intake rows for a tenant.
     * Supports filters: tier, decision_status, decided_jurisdiction, q (LIKE brand_name).
     */
    public static function list(int $tenantId, array $filters = []): array {
        $sql    = "SELECT * FROM empire_brand_intake WHERE tenant_id=?";
        $params = [$tenantId];

        if (!empty($filters['tier'])) {
            $sql .= " AND tier=?";
            $params[] = $filters['tier'];
        }
        if (!empty($filters['decision_status'])) {
            $sql .= " AND decision_status=?";
            $params[] = $filters['decision_status'];
        }
        if (!empty($filters['decided_jurisdiction'])) {
            $sql .= " AND decided_jurisdiction=?";
            $params[] = $filters['decided_jurisdiction'];
        }
        if (!empty($filters['q'])) {
            $sql .= " AND brand_name LIKE ?";
            $params[] = '%' . $filters['q'] . '%';
        }

        $sql .= " ORDER BY tier, brand_name";
        $stmt = Database::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Fetch intake row by brand_slug; returns null if not found or wrong tenant. */
    public static function getBySlug(int $tenantId, string $slug): ?array {
        $stmt = Database::get()->prepare(
            "SELECT * FROM empire_brand_intake WHERE tenant_id=? AND brand_slug=? LIMIT 1"
        );
        $stmt->execute([$tenantId, $slug]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Update decision fields on an intake row.
     * Accepted keys: decided_jurisdiction, decided_entity_type, decided_parent_kind,
     * decided_parent_entity_id, decided_trust_wrapper, decided_sale_horizon,
     * advisor_notes_md, decision_status.
     */
    public static function updateDecision(int $tenantId, int $id, array $decision): bool {
        $allowed = [
            'decided_jurisdiction', 'decided_entity_type', 'decided_parent_kind',
            'decided_parent_entity_id', 'decided_trust_wrapper', 'decided_sale_horizon',
            'advisor_notes_md', 'decision_status',
        ];
        $sets   = [];
        $params = [];
        foreach ($allowed as $col) {
            if (array_key_exists($col, $decision)) {
                $sets[]   = "{$col}=?";
                $params[] = $decision[$col];
            }
        }
        if (empty($sets)) return false;

        $params[] = $id;
        $params[] = $tenantId;
        $stmt = Database::get()->prepare(
            "UPDATE empire_brand_intake SET " . implode(',', $sets) . " WHERE id=? AND tenant_id=?"
        );
        $stmt->execute($params);
        return $stmt->rowCount() > 0;
    }

    /** Lock an intake row (status → locked, record who locked it). */
    public static function lock(int $tenantId, int $id, int $userId): bool {
        $stmt = Database::get()->prepare(
            "UPDATE empire_brand_intake
             SET decision_status='locked', decision_locked_at=NOW(), decision_locked_by=?
             WHERE id=? AND tenant_id=? AND decision_status != 'locked'"
        );
        $stmt->execute([$userId, $id, $tenantId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Spawn a formation_entities row from a locked intake record.
     * Returns the new formation_entities.id and links it back via spawned_entity_id.
     */
    public static function spawnEntity(int $tenantId, int $id): int {
        $intake = self::get($tenantId, $id);
        if (!$intake) {
            throw new \RuntimeException("IntakeRepo::spawnEntity — intake #{$id} not found for tenant {$tenantId}");
        }
        if ($intake['decision_status'] !== 'locked') {
            throw new \RuntimeException("IntakeRepo::spawnEntity — intake #{$id} must be locked before spawning");
        }

        $pdo  = Database::get();
        $stmt = $pdo->prepare(
            "INSERT INTO formation_entities
             (tenant_id, legal_name, dba_name, entity_type, formation_state, status, notes)
             VALUES (?,?,?,?,?,?,?)"
        );
        $stmt->execute([
            $tenantId,
            $intake['brand_name'],
            $intake['brand_slug'],
            $intake['decided_entity_type'] ?? 'llc',
            $intake['decided_jurisdiction'] ?? 'TX',
            'draft',
            "Spawned from Empire intake #{$id} on " . date('Y-m-d'),
        ]);
        $entityId = (int) $pdo->lastInsertId();

        $upd = $pdo->prepare(
            "UPDATE empire_brand_intake SET spawned_entity_id=? WHERE id=? AND tenant_id=?"
        );
        $upd->execute([$entityId, $id, $tenantId]);

        return $entityId;
    }
}
