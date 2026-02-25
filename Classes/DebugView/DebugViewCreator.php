<?php

namespace Neos\ContentRepository\Debug\DebugView;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;

final class DebugViewCreator
{

    public function __construct(
        private readonly Connection          $connection,
        private readonly ContentRepositoryId $contentRepositoryId,
    )
    {
    }

    public function createDebugViews(): void
    {
        $this->exec(sprintf(<<<EOF
            CREATE OR REPLACE VIEW cr_%s_dbg_allNodesInLive AS

            SELECT
                n.relationanchorpoint,
                n.nodeaggregateid,
                dsp.dimensionspacepoint as dsp_dimensionspacepoint,

                -- Content stream is always the same, for live
                -- h.contentstreamid as h_contentstreamid,

                -- h.parentnodeanchor as h_parentnodeanchor,
                pn.nodeaggregateid as p_nodeaggregateid,
                h.position as h_position,
                n.nodetypename,
                n.name,
                n.properties,

                h.subtreetags as h_subtreetags,
                -- Not needed usually
                -- n.relationanchorpoint,

                -- n.origindimensionspacepointhash,
                odsp.dimensionspacepoint as odsp_origindimensionspacepoint,
                n.classification,
                n.created,
                n.originalcreated,
                n.lastmodified,
                n.originallastmodified
            FROM cr_%s_p_graph_node n
            LEFT JOIN cr_%s_p_graph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
            LEFT JOIN cr_%s_p_graph_workspace ws ON h.contentstreamid = ws.currentContentStreamId
            LEFT JOIN cr_%s_p_graph_dimensionspacepoints dsp ON h.dimensionspacepointhash = dsp.hash
            LEFT JOIN cr_%s_p_graph_dimensionspacepoints odsp ON n.origindimensionspacepointhash = odsp.hash
            LEFT JOIN cr_%s_p_graph_node pn ON h.parentnodeanchor = pn.relationanchorpoint
            WHERE ws.name = 'live'
            ORDER by n.relationanchorpoint

            EOF,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
        ));

        $this->exec(sprintf(<<<EOF
            CREATE OR REPLACE VIEW cr_%s_dbg_allDocumentNodesInLive AS

            SELECT
                n.relationanchorpoint,
                uri.sitenodename as uri_sitenodename,
                uri.uripath as uri_uripath,
                n.nodeaggregateid,
                dsp.dimensionspacepoint as dsp_dimensionspacepoint,

                -- Content stream is always the same, for live
                -- h.contentstreamid as h_contentstreamid,

                -- h.parentnodeanchor as h_parentnodeanchor,
                pn.nodeaggregateid as p_nodeaggregateid,
                h.position as h_position,
                n.nodetypename,
                n.name,
                n.properties,

                h.subtreetags as h_subtreetags,
                -- Not needed usually
                -- n.relationanchorpoint,

                -- n.origindimensionspacepointhash,
                odsp.dimensionspacepoint as odsp_origindimensionspacepoint,
                n.classification,
                n.created,
                n.originalcreated,
                n.lastmodified,
                n.originallastmodified
            FROM cr_%s_p_graph_node n
            LEFT JOIN cr_%s_p_graph_hierarchyrelation h ON h.childnodeanchor = n.relationanchorpoint
            LEFT JOIN cr_%s_p_graph_workspace ws ON h.contentstreamid = ws.currentContentStreamId
            LEFT JOIN cr_%s_p_graph_dimensionspacepoints dsp ON h.dimensionspacepointhash = dsp.hash
            LEFT JOIN cr_%s_p_graph_dimensionspacepoints odsp ON n.origindimensionspacepointhash = odsp.hash
            LEFT JOIN cr_%s_p_graph_node pn ON h.parentnodeanchor = pn.relationanchorpoint

            LEFT JOIN cr_%s_p_neos_documenturipath_uri uri ON uri.nodeaggregateid = n.nodeaggregateid AND h.dimensionspacepointhash = uri.dimensionspacepointhash
            WHERE ws.name = 'live'
            AND uri.uripath IS NOT NULL
            ORDER by uri.sitenodename, uri.uripath

            EOF,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
            $this->contentRepositoryId->value,
        ));
    }

    private function exec(string $sqlStatement): void
    {
        echo $sqlStatement;
        $this->connection->executeStatement($sqlStatement);
    }
}
