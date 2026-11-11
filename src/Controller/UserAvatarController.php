<?php

namespace App\Controller;

use App\Entity\User;
use App\Library\LibraryFiles;
use App\Library\Thumbnails;
use App\Library\UnreadableImage;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Profile pictures. Uploaded as multipart/form-data (field "file": JPEG, PNG or WebP, up to 5 MB) by the account
 * itself (/api/me/avatar) or an administrator (/api/users/{id}/avatar), then made into a square JPEG of up to 300×300
 * by App\Library\Thumbnails: decoded and encoded again, so no metadata (EXIF, location) and nothing but an image is
 * ever served. Kept in the media folder, served by Caddy like covers, to a JWT or the signature of the account's
 * avatarUrl (<img> can't send headers). Documented by App\OpenApi\UserAvatarsOpenApiFactory.
 */
final class UserAvatarController extends AbstractController
{
    public const int MAX_BYTES = 5 * 1024 * 1024;

    /** What the picture really is, read from its content by getimagesize(): never what the client says it is. */
    private const array TYPES = [\IMAGETYPE_JPEG, \IMAGETYPE_PNG, \IMAGETYPE_WEBP];

    /** Pictures change rarely, and their URL changes with them. */
    private const int MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly UserRepository $users,
        private readonly EntityManagerInterface $entityManager,
        private readonly Thumbnails $thumbnails,
        private readonly LibraryFiles $files,
    ) {
    }

    #[Route('/api/me/avatar', name: 'my_avatar', methods: ['POST', 'DELETE'])]
    public function mine(Request $request): Response
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $this->change($request, $user);
    }

    #[Route('/api/users/{id}/avatar', name: 'user_avatar_change', requirements: ['id' => Requirement::UUID], methods: ['POST', 'DELETE'])]
    public function someones(Request $request, string $id): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_ADMIN);

        return $this->change($request, $this->find($id));
    }

    #[Route('/api/users/{id}/avatar', name: 'user_avatar', requirements: ['id' => Requirement::UUID], methods: ['GET', 'HEAD'])]
    public function show(Request $request, string $id): BinaryFileResponse
    {
        $this->files->denyUnlessAuthorized($request);

        $avatar = $this->find($id)->getAvatar();
        $file = (null !== $avatar ? $this->thumbnails->file($avatar) : null) ?? throw $this->createNotFoundException('This account has no picture.');

        $response = $this->files->fileResponse($file, 'image/jpeg');
        $response->setMaxAge(self::MAX_AGE_SECONDS);

        return $response;
    }

    private function change(Request $request, User $user): Response
    {
        if ($request->isMethod('DELETE')) {
            $this->thumbnails->remove($user->getAvatar());
            $user->setAvatar(null);
            $this->entityManager->flush();

            return new Response(status: Response::HTTP_NO_CONTENT);
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            throw new UnprocessableEntityHttpException('Send the picture as multipart/form-data, in a field named "file".');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new UnprocessableEntityHttpException(\sprintf('The picture is too large: %d MB at most.', self::MAX_BYTES / 1024 / 1024));
        }
        $size = @getimagesize($file->getPathname());
        if (false === $size || !\in_array($size[2], self::TYPES, true)) {
            throw new UnprocessableEntityHttpException('Send a JPEG, PNG or WebP picture.');
        }

        try {
            $avatar = $this->thumbnails->of($file->getPathname(), 'avatar-'.$user->getId()->toRfc4122());
        } catch (UnreadableImage $e) {
            throw new UnprocessableEntityHttpException('This picture cannot be read.', $e);
        }
        $user->setAvatar(basename($avatar));
        $this->entityManager->flush();

        return $this->json($user, context: ['groups' => ['user:read']]);
    }

    private function find(string $id): User
    {
        return $this->users->find(Uuid::fromString($id)) ?? throw $this->createNotFoundException('No account with this id.');
    }
}
