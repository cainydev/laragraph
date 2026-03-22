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
import type { RunState } from '../hooks/useRunSubscription'

interface WorkflowMeta {
    name: string
    label: string
    description: string
    nodes: string[]
    edges: WorkflowEdgeDef[]
}

interface Props {
    workflow: WorkflowMeta
}

const csrf = (): string =>
    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''

const INITIAL_RUN_STATE: RunState = {
    id: 0,
    key: '',
    status: 'pending',
    state: {},
    active_pointers: [],
    error: null,
}

const MIN_PANEL_PX = 240
const MAX_PANEL_FRACTION = 0.5

export default function WorkflowDetail({ workflow }: Props) {
    const [starting, setStarting] = useState(false)

    const handleStart = async () => {
        setStarting(true)
        try {
            const res = await fetch(`/api/workflows/${workflow.name}/run`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
            })
            const { runId } = (await res.json()) as { runId: number }
            router.visit(`/run/${runId}`)
        } catch {
            setStarting(false)
        }
    }

    // Resizable state panel (left side)
    const [panelWidth, setPanelWidth] = useState(420)
    const containerRef = useRef<HTMLDivElement>(null)
    const dragging = useRef(false)
    const startX = useRef(0)
    const startWidth = useRef(0)

    const onMouseDown = useCallback(
        (e: React.MouseEvent) => {
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
        },
        [panelWidth],
    )

    return (
        <div className="flex flex-col h-screen bg-gray-950 text-gray-100">
            <header className="flex items-center justify-between px-6 py-3 border-b border-gray-800 shrink-0">
                <div className="flex items-center gap-3">
                    <Link href="/" className="text-sm text-gray-400 hover:text-white transition-colors">
                        ← Workflows
                    </Link>
                    <span className="text-gray-700">/</span>
                    <span className="text-sm font-semibold text-white">{workflow.label}</span>
                </div>
                <button
                    onClick={() => void handleStart()}
                    disabled={starting}
                    className="bg-blue-600 hover:bg-blue-500 disabled:opacity-50 disabled:cursor-not-allowed text-white text-sm font-medium px-4 py-1.5 rounded transition-colors"
                >
                    {starting ? 'Starting...' : 'Start Run'}
                </button>
            </header>

            <div className="px-6 py-3 border-b border-gray-800 shrink-0">
                <p className="text-sm text-gray-400">{workflow.description}</p>
            </div>

            <div ref={containerRef} className="flex flex-1 overflow-hidden">
                {/* State panel (left) */}
                <div
                    className="shrink-0 border-r border-gray-800 overflow-hidden flex flex-col"
                    style={{ width: panelWidth }}
                >
                    <div className="px-4 py-2 border-b border-gray-800 flex items-center justify-between">
                        <span className="text-xs uppercase tracking-widest text-gray-500 font-semibold">State</span>
                        <CopyJsonButton data={{
                            run: { workflow: workflow.name, status: 'pending', active_pointers: [] },
                            state: {},
                        }} />
                    </div>
                    <div className="flex-1 overflow-auto">
                        <StateViewer runState={INITIAL_RUN_STATE} />
                    </div>
                </div>

                {/* Drag handle */}
                <div
                    onMouseDown={onMouseDown}
                    className="w-1 shrink-0 cursor-col-resize bg-gray-800 hover:bg-blue-500 active:bg-blue-400 transition-colors"
                />

                {/* Graph (right) */}
                <div className="flex-1 overflow-hidden">
                    <WorkflowCanvas nodeNames={workflow.nodes} edges={workflow.edges} nodeStatuses={{}} />
                </div>
            </div>
        </div>
    )
}
