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
    private const LIST_BATCH_SIZE = 250;
    private const LIST_PROGRESS_INTERVAL = 500;
    private const DELETE_BATCH_SIZE = 100;
    private const DELETE_PROGRESS_INTERVAL = 100;

    /**
     * List unused assets for an optional volume/folder scope.
     *
     * @param string|null $volume The volume handle to filter on.
     * @param string|null $path The folder path to filter on (for example: images/team or images/team/).
     * 
     * @return int Exit code.
     * @throws InvalidArgumentException When a non-null path cannot be resolved.
     */
    public function actionListUnused(?string $volume = null, ?string $path = null): int
    {
        $this->stdout('Listing all unused asset ids:' . PHP_EOL);

        $query = $this->createUnusedAssetsQuery($volume, $path);
        $listed = 0;

        foreach ($query->each(self::LIST_BATCH_SIZE) as $result) {
            $listed++;
            $this->stdout($result['id'] . ' : ' . $result['filename'] . PHP_EOL);

            if ($listed % self::LIST_PROGRESS_INTERVAL === 0) {
                $this->stdout("Progress: listed {$listed} unused assets..." . PHP_EOL);
            }
        }

        $this->stdout("Done. Listed {$listed} unused assets." . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Delete unused assets for an optional volume/folder scope.
     *
     * @param string|null $volume The volume handle to filter on.
     * @param string|null $path The folder path to filter on (for example: images/team or images/team/).
     * 
     * @return int Exit code.
     * @throws InvalidArgumentException When a non-null path cannot be resolved.
     */
    public function actionDeleteUnused(?string $volume = null, ?string $path = null): int
    {
        $this->stdout('Deleting all unused asset ids:' . PHP_EOL);

        $assets = Craft::$app->getAssets();
        $query = $this->createUnusedAssetsQuery($volume, $path);
        $count = $this->getUnusedAssetCount($query);

        if ($this->confirm("Delete {$count} assets?")) {
            $deleted = 0;

            foreach ($query->each(self::DELETE_BATCH_SIZE) as $result) {
                $this->stdout('Deleting ' . $result['id'] . ' : ' . $result['filename'] . PHP_EOL);

                $asset = $assets->getAssetById($result['id']);
                
                if ($asset) {
                    Craft::$app->getElements()->deleteElement($asset);
                }

                $deleted++;
                if ($deleted % self::DELETE_PROGRESS_INTERVAL === 0 || $deleted === $count) {
                    $this->stdout("Progress: deleted {$deleted}/{$count} assets..." . PHP_EOL);
                }
            }

            $this->stdout('Deleted all unused asset ids.' . PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * Build the query that selects unused assets.
     *
     * @param string|null $volume The volume handle to filter on.
     * @param string|null $path The folder path to filter on.
     * 
     * @return Query The configured query selecting asset ids and filenames.
     * @throws InvalidArgumentException When a non-null path cannot be resolved.
     */
    private function createUnusedAssetsQuery(?string $volume = null, ?string $path = null): Query
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

        return $query;
    }

    /**
     * Count rows produced by an unused-assets query.
     *
     * @param Query $query The base unused-assets query.
     * @return int The number of matching assets.
     */
    private function getUnusedAssetCount(Query $query): int
    {
        $countQuery = clone $query;

        return (int)$countQuery->count('*', Craft::$app->getDb());
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
