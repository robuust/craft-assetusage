<?php

namespace roelvanhintum\assetusage\console\controllers;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\models\Volume;
use yii\base\InvalidArgumentException;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Controls
 */
class DefaultController extends Controller
{
    /**
     * Lists all unused assets.
     * @param string|null $volume The handle of the asset's volume.
     * @param string|null $path The asset folder path (for example: images/team or images/team/).
     */
    public function actionListUnused(?string $volume = null, ?string $path = null): int
    {
        $this->stdout('Listing all unused asset ids:' . PHP_EOL);

        $results = $this->getUnusedAssets($volume, $path);
        foreach ($results as $result) {
            $this->stdout($result['id'] . ' : ' . $result['filename'] . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * Deletes all unused assets.
     * @param string|null $volume The handle of the asset's volume.
     * @param string|null $path The asset folder path (for example: images/team or images/team/).
     */
    public function actionDeleteUnused(?string $volume = null, ?string $path = null): int
    {
        $this->stdout('Deleting all unused asset ids:' . PHP_EOL);

        $results = $this->getUnusedAssets($volume, $path);
        $assetCount = count($results);

        if ($this->confirm("Delete $assetCount assets?")) {
            $assets = Craft::$app->getAssets();

            foreach ($results as $result) {
                $this->stdout('Deleting ' . $result['id'] . ' : ' . $result['filename'] . PHP_EOL);

                $asset = $assets->getAssetById($result['id']);
                if ($asset) {
                    Craft::$app->getElements()->deleteElement($asset);
                }
            }

            $this->stdout('Deleted all unused asset ids.' . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * @param string|null $volume The handle of the asset's volume.
     * @param string|null $path The asset folder path. When set, results are limited to this folder and descendants.
     * @return array<int, array{id: int, filename: string}>
     */
    private function getUnusedAssets(?string $volume = null, ?string $path = null): array
    {
        $volumeModel = $this->getVolumeByHandle($volume);
        $folder = $this->getFolderByPath($path, $volumeModel?->id);

        if ($path !== null && $folder === null) {
            throw new InvalidArgumentException(sprintf('Unable to resolve folder by path "%s".', $path));
        }

        $subQueryRelations = (new Query())
            ->select('id')
            ->from(['relations' => Table::RELATIONS])
            ->where('[[relations.targetId]]=[[assets.id]]')
            ->orWhere('[[relations.sourceId]]=[[assets.id]]');

        $subQueryContent = (new Query())
            ->select('elementId as id')
            ->from(Table::ELEMENTS_SITES);

        // PostgreSQL requires explicit casting for JSONB columns
        if (Craft::$app->getDb()->getIsPgsql()) {
            $subQueryContent
                ->where("CAST(content AS TEXT) LIKE CONCAT('%asset:', assets.id, ':%')")
                ->orWhere("CAST(content AS TEXT) LIKE CONCAT('%\"imageId\": \"', assets.id, '\",%')");
        } else {
            $subQueryContent
                ->where("`content` LIKE CONCAT('%asset:', assets.id, ':%')")
                ->orWhere("`content` LIKE CONCAT('%\"imageId\": \"', assets.id, '\",%')");
        }

        $query = (new Query())
            ->select(['assets.id', 'assets.filename'])
            ->from(['assets' => Table::ASSETS])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[assets.id]]')
            ->where(['elements.dateDeleted' => null])
            ->andWhere(['not exists', $subQueryRelations])
            ->andWhere(['not exists', $subQueryContent]);

        if ($volumeModel !== null) {
            $query->andWhere(['assets.volumeId' => $volumeModel->id]);
        }

        if ($folder !== null) {
            $descendantFolderIds = (new Query())
                ->select('id')
                ->from(Table::VOLUMEFOLDERS)
                ->where(['volumeId' => $folder['volumeId']])
                ->andWhere(['like', 'path', $folder['path'] . '%', false]);

            $query->andWhere(['in', 'assets.folderId', $descendantFolderIds]);
        }

        return $query->all();
    }

    /**
     * @param string|null $volumeHandle The volume handle.
     */
    private function getVolumeByHandle(?string $volumeHandle): ?Volume
    {
        if ($volumeHandle === null) {
            return null;
        }

        return Craft::$app->getVolumes()->getVolumeByHandle($volumeHandle);
    }

    /**
     * @param string|null $path The asset folder path (with or without trailing slash).
     * @param int|null $volumeId The volume id.
     * @return array{id: int, volumeId: int, path: string}|null
     */
    private function getFolderByPath(?string $path, ?int $volumeId): ?array
    {
        if ($path === null) {
            return null;
        }

        $normalizedPath = $this->normalizePath($path);
        $query = (new Query())
            ->select(['id', 'volumeId', 'path'])
            ->from(Table::VOLUMEFOLDERS)
            ->where(['path' => $normalizedPath]);

        if ($volumeId !== null) {
            $query->andWhere(['volumeId' => $volumeId]);
        }

        $folders = $query->all();
        if (count($folders) !== 1) {
            return null;
        }

        return $folders[0];
    }

    /**
     * @param string $path The raw asset folder path argument.
     */
    private function normalizePath(string $path): string
    {
        $trimmedPath = trim($path);
        $trimmedPath = trim($trimmedPath, '/');

        if ($trimmedPath === '') {
            return '';
        }

        return $trimmedPath . '/';
    }
}
