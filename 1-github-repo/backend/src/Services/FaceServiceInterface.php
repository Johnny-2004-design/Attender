<?php
declare(strict_types=1);

namespace Attender\Services;

/**
 * Server-side face matching + liveness interface.
 *
 * Implementations must NEVER fabricate a successful match when the provider
 * is unavailable. Prefer returning pending/fail with a clear reason.
 *
 * enroll(): ok=true AND face_enrolled=true ONLY when a real embedding was saved
 * by the face engine (Python). Local JPEG audit storage alone is not enrollment.
 */
interface FaceServiceInterface
{
    /**
     * @param list<string> $imageDataUrls base64 data URLs from client capture
     * @param array{descriptors?:list<list<float>>} $extra provider-specific data (browser provider: face descriptors)
     * @return array{
     *   ok:bool,
     *   face_enrolled:bool,
     *   embedding_ready?:bool,
     *   template_id?:int,
     *   reason?:string,
     *   provider:string
     * }
     */
    public function enroll(int $studentId, ?int $lecturerId, array $imageDataUrls, array $extra = []): array;

    /**
     * @param list<string> $imageDataUrls
     * @param array{challenge?:string, frames?:list<string>} $liveness
     * @return array{
     *   ok:bool,
     *   pending?:bool,
     *   similarity?:float|null,
     *   liveness_ok?:bool|null,
     *   identity_ok?:bool|null,
     *   reason?:string,
     *   provider:string
     * }
     */
    public function verify(int $studentId, array $imageDataUrls, array $liveness = []): array;

    /** Soft-delete / remove gallery embedding for a student (lecturer-scoped on PHP side). */
    public function deleteEnrollment(int $studentId, ?int $lecturerId = null): array;

    public function providerName(): string;

    public function isConfigured(): bool;

    /** True when an active embedding_ready template exists for this student. */
    public function hasReadyEmbedding(int $studentId): bool;
}
