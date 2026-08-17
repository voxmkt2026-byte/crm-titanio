<?php
/**
 * app/models/PipelineStage.php
 */

require_once APP_PATH . '/core/Model.php';

class PipelineStage extends Model
{
    protected string $table = 'pipeline_stages';

    public function allOrdered(): array
    {
        $stmt = $this->db->query("SELECT * FROM pipeline_stages ORDER BY order_position ASC");
        return $stmt->fetchAll();
    }
}
