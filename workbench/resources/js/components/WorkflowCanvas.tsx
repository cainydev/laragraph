import {
    Background,
    Controls,
    type Edge,
    type Node,
    Position,
    Handle,
    ReactFlow,
    applyEdgeChanges,
    applyNodeChanges,
    useReactFlow,
} from '@xyflow/react'
import '@xyflow/react/dist/style.css'
import dagre from 'dagre'
import { useCallback, useEffect, useRef, useState } from 'react'
import type { NodeStatus } from '../hooks/useRunSubscription'

// ─── Types ───────────────────────────────────────────────────────────────────

export interface WorkflowEdgeDef {
    type: string
    from: string
    to?: string
    when?: string | null
    targets?: string[]
}

interface Props {
    nodeNames: string[]
    edges: WorkflowEdgeDef[]
    nodeStatuses: Record<string, NodeStatus>
}

// ─── Node component ───────────────────────────────────────────────────────────

const STATUS_COLORS: Record<NodeStatus | 'start' | 'end', string> = {
    idle: 'bg-gray-700 border-gray-500 text-gray-200',
    executing: 'bg-blue-800 border-blue-400 text-blue-100 animate-pulse',
    completed: 'bg-green-900 border-green-400 text-green-100',
    failed: 'bg-red-900 border-red-400 text-red-100',
    start: 'bg-gray-800 border-gray-400 text-gray-300',
    end: 'bg-gray-800 border-gray-400 text-gray-300',
}

interface WorkflowNodeData extends Record<string, unknown> {
    label: string
    status: NodeStatus | 'start' | 'end'
}

// Handles on all 4 sides so React Flow can route edges freely
function WorkflowNode({ data }: { data: WorkflowNodeData }) {
    const colorClass = STATUS_COLORS[data.status] ?? STATUS_COLORS.idle
    return (
        <div className={`px-4 py-2 rounded-lg border-2 text-xs font-mono min-w-[100px] text-center ${colorClass}`}>
            <Handle type="target" position={Position.Left}   id="l" className="!bg-gray-400 !w-2 !h-2" />
            <Handle type="target" position={Position.Top}    id="t" className="!bg-gray-400 !w-2 !h-2" />
            <Handle type="source" position={Position.Right}  id="r" className="!bg-gray-400 !w-2 !h-2" />
            <Handle type="source" position={Position.Bottom} id="b" className="!bg-gray-400 !w-2 !h-2" />
            {data.label}
        </div>
    )
}

const nodeTypes = { workflowNode: WorkflowNode }

// ─── ELK layout ──────────────────────────────────────────────────────────────

const NODE_WIDTH  = 130
const NODE_HEIGHT = 36

function computeDagreLayout(
    nodeIds: string[],
    edgeDefs: WorkflowEdgeDef[],
): Record<string, { x: number; y: number }> {
    const g = new dagre.graphlib.Graph()
    g.setDefaultEdgeLabel(() => ({}))
    g.setGraph({ rankdir: 'LR', nodesep: 60, ranksep: 80 })

    for (const id of nodeIds) {
        g.setNode(id, { width: NODE_WIDTH, height: NODE_HEIGHT })
    }

    for (const e of edgeDefs) {
        if (e.to) g.setEdge(e.from, e.to)
        if (e.targets) {
            for (const t of e.targets) g.setEdge(e.from, t)
        }
    }

    dagre.layout(g)

    const positions: Record<string, { x: number; y: number }> = {}
    for (const id of nodeIds) {
        const n = g.node(id)
        positions[id] = { x: n.x - NODE_WIDTH / 2, y: n.y - NODE_HEIGHT / 2 }
    }
    return positions
}

// ─── Build React Flow nodes/edges ─────────────────────────────────────────────

function buildRFNodes(
    nodeIds: string[],
    positions: Record<string, { x: number; y: number }>,
    nodeStatuses: Record<string, NodeStatus>,
): Node<WorkflowNodeData>[] {
    return nodeIds.map((id) => {
        const isStart = id === '__START__'
        const isEnd   = id === '__END__'
        const status: NodeStatus | 'start' | 'end' = isStart ? 'start' : isEnd ? 'end' : (nodeStatuses[id] ?? 'idle')
        return {
            id,
            type: 'workflowNode' as const,
            position: positions[id] ?? { x: 0, y: 0 },
            data: { label: isStart ? 'START' : isEnd ? 'END' : id, status },
        }
    })
}

function buildRFEdges(
    edgeDefs: WorkflowEdgeDef[],
    positions: Record<string, { x: number; y: number }>,
): Edge[] {
    const result: Edge[] = []
    let ei = 0

    const depthOf = (id: string) => positions[id]?.x ?? 0

    const makeEdge = (from: string, to: string, dashed: boolean, label?: string): Edge => {
        const backward = depthOf(to) < depthOf(from)
        return {
            id: `e-${ei++}`,
            source: from,
            target: to,
            sourceHandle: backward ? 'b' : 'r',
            targetHandle: backward ? 't' : 'l',
            type: backward ? 'bezier' : 'smoothstep',
            label,
            labelStyle: { fontSize: 10, fill: '#9ca3af' },
            style: {
                stroke: dashed ? '#4b5563' : '#6b7280',
                strokeDasharray: dashed ? '5,5' : undefined,
            },
            animated: dashed,
        }
    }

    for (const e of edgeDefs) {
        if (e.to) {
            result.push(makeEdge(e.from, e.to, false, e.when ?? undefined))
        }
        if (e.type === 'branch' && e.targets) {
            for (const t of e.targets) {
                result.push(makeEdge(e.from, t, true))
            }
        }
    }

    return result
}

// ─── FitView helper (must live inside <ReactFlow> to access the store) ────────

function FitViewOnLayout({ trigger }: { trigger: number }) {
    const { fitView } = useReactFlow()
    useEffect(() => {
        if (trigger > 0) {
            setTimeout(() => void fitView({ padding: 0.3, duration: 200 }), 50)
        }
    }, [trigger, fitView])
    return null
}

// ─── Main component ───────────────────────────────────────────────────────────

export function WorkflowCanvas({ nodeNames, edges, nodeStatuses }: Props) {
    const allNodeIds = ['__START__', ...nodeNames, '__END__']

    const [rfNodes, setRfNodes] = useState<Node<WorkflowNodeData>[]>([])
    const [rfEdges, setRfEdges] = useState<Edge[]>([])
    const [fitTrigger, setFitTrigger] = useState(0)
    const positionsRef = useRef<Record<string, { x: number; y: number }>>({})

    const layout = useCallback(() => {
        const positions = computeDagreLayout(allNodeIds, edges)
        positionsRef.current = positions
        setRfNodes(buildRFNodes(allNodeIds, positions, nodeStatuses))
        setRfEdges(buildRFEdges(edges, positions))
        setFitTrigger((n) => n + 1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [nodeNames, edges])

    // Re-layout when graph structure changes
    useEffect(() => { layout() }, [layout])

    // Update node colors without re-layouting
    useEffect(() => {
        setRfNodes((prev) =>
            prev.map((n) => {
                const isStart = n.id === '__START__'
                const isEnd   = n.id === '__END__'
                const status: NodeStatus | 'start' | 'end' = isStart ? 'start' : isEnd ? 'end' : (nodeStatuses[n.id] ?? 'idle')
                return { ...n, data: { ...n.data, status } }
            }),
        )
    }, [nodeStatuses])

    const onNodesChange = useCallback(
        (changes: Parameters<typeof applyNodeChanges>[0]) =>
            setRfNodes((ns) => applyNodeChanges(changes, ns) as Node<WorkflowNodeData>[]),
        [],
    )
    const onEdgesChange = useCallback(
        (changes: Parameters<typeof applyEdgeChanges>[0]) =>
            setRfEdges((es) => applyEdgeChanges(changes, es)),
        [],
    )

    return (
        <div className="w-full h-full bg-gray-950">
            <ReactFlow
                nodes={rfNodes}
                edges={rfEdges}
                onNodesChange={onNodesChange}
                onEdgesChange={onEdgesChange}
                nodeTypes={nodeTypes}
                fitView
                fitViewOptions={{ padding: 0.3 }}
                colorMode="dark"
                nodesFocusable={false}
                edgesFocusable={false}
            >
                <FitViewOnLayout trigger={fitTrigger} />
                <Background color="#374151" gap={20} />
                <Controls />
            </ReactFlow>
        </div>
    )
}
