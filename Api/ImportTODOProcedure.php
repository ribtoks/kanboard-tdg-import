<?php

namespace Kanboard\Plugin\TdgImport\Api;

use Kanboard\Api\Procedure\BaseProcedure;
use Kanboard\Core\Base;

/**
 * ImportTODO API controller
 *
 * @author   Taras Kushnir
 */
class ImportTODOProcedure extends BaseProcedure
{
	public function importTodoComments($root, $branch, $author, $project, array $comments)
	{
       ///ProjectAuthorization::getInstance($this->container)->check($this->getClassName(), 'importTodoComments', $todoTasks);

        $project_id = $this->projectModel->getByName($project)['id'];

		foreach ($comments as $c) {
			$values = array(
				'title' => $c['title'],
                    'project_id' => $project_id,
                    //'color_id' => $color_id,
                    //'column_id' => $column_id,
                    //'owner_id' => $owner_id,
                    //'creator_id' => $creator_id,
                    'description' => $c['body'],
                    //'category_id' => $category_id,
                    //'reference' => $reference,
                    //'tags' => $tags,
                    //'date_started' => $date_started,
                );

			list($valid, ) = $this->taskValidator->validateCreation($values);

			if ($valid) {
				$this->taskCreationModel->create($values);
			}
		}
		return true;
        //return $this->taskApiFormatter->withTask($task)->format();
	}
}