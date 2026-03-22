import { Link, router } from '@inertiajs/react'
import { useCallback, useRef, useState } from 'react'

function CopyJsonButton({ data }: { data: unknown }) {
    const [copied, setCopied] = useState(false)
    const handleCopy = () => {
        void navigator.clipboard.writeText(JSON.stringify(data, null, 2)).then(() => {
            setCopied(true)
            setTimeout(() => setCopied(false), 1500)
        })
    }
    return (
        <button
            onClick={handleCopy}
            className="text-[10px] font-sans px-2 py-0.5 rounded border border-gray-700 text-gray-500 hover:text-gray-300 hover:border-gray-500 transition-colors"
        >
            {copied ? 'Copied!' : 'Copy JSON'}
        </button>
    )
}
import { StateViewer } from '../components/StateViewer'
import { WorkflowCanvas, type WorkflowEdgeDef } from '../components/WorkflowCanvas'
import { type RunState, useRunSubscription } from '../hooks/useRunSubscription'

interface WorkflowMeta {
    name: string
    label: string
    nodes: string[]
    edges: WorkflowEdgeDef[]
}

interface Props {
    run: RunState
    workflow: WorkflowMeta | null
}

const STATUS_BADGE: Record<string, string> = {
    running: 'bg-blue-600',
    paused: 'bg-yellow-600',
    completed: 'bg-green-600',
    failed: 'bg-red-600',
    pending: 'bg-gray-600',
}

const MIN_PANEL_PX = 240
const MAX_PANEL_FRACTION = 0.5

export default function RunDetail({ run: initialRun, workflow }: Props) {
    const { runState, nodeStatuses, pause, abort, resume } = useRunSubscription(initialRun.id, initialRun)

    const isRunning = runState.status === 'running'
    const isPaused = runState.status === 'paused'
    const isTerminal = runState.status === 'completed' || runState.status === 'failed'

    const [feedbackInput, setFeedbackInput] = useState('')
    const [rerunning, setRerunning] = useState(false)
    const handleRerun = async () => {
        if (!workflow) return
        setRerunning(true)
        try {
            const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''
            const res = await fetch(`/api/workflows/${workflow.name}/run`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
            })
            const { runId } = (await res.json()) as { runId: number }
            router.visit(`/run/${runId}`)
        } catch {
            setRerunning(false)
        }
    }

    // Resizable state panel (left side)
    const [panelWidth, setPanelWidth] = useState(420)
    const containerRef = useRef<HTMLDivElement>(null)
    const dragging = useRef(false)
    const startX = useRef(0)
    const startWidth = useRef(0)

    const onMouseDown = useCallback((e: React.MouseEvent) => {
        e.preventDefault()
        dragging.current = true
        startX.current = e.clientX
        startWidth.current = panelWidth

        const onMove = (e: MouseEvent) => {
            if (!dragging.current) return
            const containerW = containerRef.current?.offsetWidth ?? window.innerWidth
            const maxPx = containerW * MAX_PANEL_FRACTION
            const delta = e.clientX - startX.current
            setPanelWidth(Math.max(MIN_PANEL_PX, Math.min(maxPx, startWidth.current + delta)))
        }

        const onUp = () => {
            dragging.current = false
            window.removeEventListener('mousemove', onMove)
            window.removeEventListener('mouseup', onUp)
        }

        window.addEventListener('mousemove', onMove)
        window.addEventListener('mouseup', onUp)
    }, [panelWidth])

    return (
        <div className="flex flex-col h-screen bg-gray-950 text-gray-100">
            {/* Header */}
            <header className="flex items-center justify-between px-6 py-3 border-b border-gray-800 shrink-0 gap-4">
                <div className="flex items-center gap-3 min-w-0">
                    {workflow && (
                        <>
                            <Link
                                href={`/workflow/${workflow.name}`}
                                className="text-sm text-gray-400 hover:text-white transition-colors shrink-0"
                            >
                                ← {workflow.label}
                            </Link>
                            <span className="text-gray-700">/</span>
                        </>
                    )}
                    <span className="text-sm font-semibold text-white truncate">Run #{runState.id}</span>
                    <span
                        className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium text-white shrink-0 ${STATUS_BADGE[runState.status] ?? 'bg-gray-600'}`}
                    >
                        <span className="w-1.5 h-1.5 rounded-full bg-white/70 inline-block" />
                        {runState.status}
                    </span>
                </div>

                <div className="flex items-center gap-2 shrink-0">
                    {isRunning && (
                        <>
                            <button
                                onClick={() => void pause()}
                                className="text-sm px-3 py-1.5 rounded border border-yellow-700 text-yellow-400 hover:bg-yellow-900/40 transition-colors"
                            >
                                Pause
                            </button>
                            <button
                                onClick={() => void abort()}
                                className="text-sm px-3 py-1.5 rounded border border-red-700 text-red-400 hover:bg-red-900/40 transition-colors"
                            >
                                Abort
                            </button>
                        </>
                    )}
                    {isPaused && workflow?.name === 'safe-publisher' && (
                        <>
                            <input
                                type="text"
                                value={feedbackInput}
                                onChange={(e) => setFeedbackInput(e.target.value)}
                                placeholder="Feedback (optional)..."
                                className="text-sm px-3 py-1.5 rounded border border-gray-700 bg-gray-900 text-gray-200 placeholder-gray-600 focus:outline-none focus:border-gray-500 w-52"
                            />
                            <button
                                onClick={() => void resume({ meta: { approved: true } })}
                                className="text-sm px-3 py-1.5 rounded border border-green-700 text-green-400 hover:bg-green-900/40 transition-colors"
                            >
                                Approve
                            </button>
                            <button
                                onClick={() => void resume({ meta: { approved: false, feedback: feedbackInput || 'Rejected.' } })}
                                className="text-sm px-3 py-1.5 rounded border border-yellow-700 text-yellow-400 hover:bg-yellow-900/40 transition-colors"
                            >
                                Reject &amp; Redraft
                            </button>
                            <button
                                onClick={() => void abort()}
                                className="text-sm px-3 py-1.5 rounded border border-red-700 text-red-400 hover:bg-red-900/40 transition-colors"
                            >
                                Abort
                            </button>
                        </>
                    )}
                    {isPaused && workflow?.name !== 'safe-publisher' && (
                        <>
                            <button
                                onClick={() => void resume()}
                                className="text-sm px-3 py-1.5 rounded border border-blue-700 text-blue-400 hover:bg-blue-900/40 transition-colors"
                            >
                                Resume
                            </button>
                            <button
                                onClick={() => void abort()}
                                className="text-sm px-3 py-1.5 rounded border border-red-700 text-red-400 hover:bg-red-900/40 transition-colors"
                            >
                                Abort
                            </button>
                        </>
                    )}
                    {isTerminal && workflow && (
                        <>
                            <Link
                                href={`/workflow/${workflow.name}`}
                                className="text-sm px-3 py-1.5 rounded border border-gray-700 text-gray-400 hover:text-white hover:border-gray-500 transition-colors"
                            >
                                Clear
                            </Link>
                            <button
                                onClick={() => void handleRerun()}
                                disabled={rerunning}
                                className="text-sm px-3 py-1.5 rounded border border-blue-700 text-blue-400 hover:bg-blue-900/40 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                            >
                                {rerunning ? 'Starting...' : 'Re-run'}
                            </button>
                        </>
                    )}
                </div>
            </header>

            {/* Split layout */}
            <div ref={containerRef} className="flex flex-1 overflow-hidden">
                {/* State panel (left) */}
                <div
                    className="shrink-0 border-r border-gray-800 overflow-hidden flex flex-col"
                    style={{ width: panelWidth }}
                >
                    <div className="px-4 py-2 border-b border-gray-800 flex items-center justify-between">
                        <span className="text-xs uppercase tracking-widest text-gray-500 font-semibold">State</span>
                        <CopyJsonButton data={{
                            run: {
                                id: runState.id,
                                workflow: workflow?.name ?? runState.key,
                                status: runState.status,
                                active_pointers: runState.active_pointers,
                            },
                            state: runState.state,
                        }} />
                    </div>
                    <div className="flex-1 overflow-auto">
                        <StateViewer runState={runState} />
                    </div>
                </div>

                {/* Drag handle */}
                <div
                    onMouseDown={onMouseDown}
                    className="w-1 shrink-0 cursor-col-resize bg-gray-800 hover:bg-blue-500 active:bg-blue-400 transition-colors"
                />

                {/* Graph (right) */}
                <div className="flex-1 overflow-hidden">
                    {workflow ? (
                        <WorkflowCanvas
                            key={workflow.name}
                            nodeNames={workflow.nodes}
                            edges={workflow.edges}
                            nodeStatuses={nodeStatuses}
                        />
                    ) : (
                        <div className="flex items-center justify-center h-full text-gray-500 text-sm">
                            Workflow graph unavailable.
                        </div>
                    )}
                </div>
            </div>
        </div>
    )
}
