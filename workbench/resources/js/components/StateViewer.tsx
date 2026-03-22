import { useEffect, useRef, useState } from 'react'
import type { RunState } from '../hooks/useRunSubscription'

// Flash a key for 1.2s when its value changes
function useChangedKeys(data: Record<string, unknown>): Set<string> {
    const prevRef = useRef<Record<string, unknown>>({})
    const [changedKeys, setChangedKeys] = useState<Set<string>>(new Set())
    const timerRef = useRef<Map<string, ReturnType<typeof setTimeout>>>(new Map())

    useEffect(() => {
        const prev = prevRef.current
        const newlyChanged: string[] = []

        for (const key of Object.keys(data)) {
            if (JSON.stringify(data[key]) !== JSON.stringify(prev[key])) {
                newlyChanged.push(key)
            }
        }

        if (newlyChanged.length > 0) {
            setChangedKeys((prev) => new Set([...prev, ...newlyChanged]))
            for (const key of newlyChanged) {
                const existing = timerRef.current.get(key)
                if (existing) clearTimeout(existing)
                const t = setTimeout(() => {
                    setChangedKeys((prev) => {
                        const next = new Set(prev)
                        next.delete(key)
                        return next
                    })
                    timerRef.current.delete(key)
                }, 1200)
                timerRef.current.set(key, t)
            }
        }

        prevRef.current = data
    }, [data])

    return changedKeys
}

function ValueDisplay({ value }: { value: unknown }) {
    if (value === null) return <span className="text-gray-500">null</span>
    if (value === undefined) return <span className="text-gray-500">undefined</span>

    if (typeof value === 'boolean')
        return <span className="text-purple-400">{value ? 'true' : 'false'}</span>

    if (typeof value === 'number')
        return <span className="text-yellow-300">{String(value)}</span>

    if (typeof value === 'string')
        return <span className="text-green-400">"{value}"</span>

    if (Array.isArray(value)) {
        if (value.length === 0) return <span className="text-gray-500">[]</span>
        return (
            <div className="mt-0.5 space-y-2">
                {value.map((item, i) => (
                    <div key={i} className="flex items-start gap-2">
                        <span className="text-gray-600 shrink-0">{i}</span>

                        <div className="flex gap-2 border-l border-gray-700 ">
                            <ValueDisplay value={item} />
                        </div>
                    </div>
                ))}
            </div>
        )
    }

    if (typeof value === 'object') {
        const entries = Object.entries(value as Record<string, unknown>)
        if (entries.length === 0) return <span className="text-gray-500">{'{}'}</span>
        return (
            <div className="pl-2 mt-0.5 space-y-0.5">
                {entries.map(([k, v]) => (
                    <div key={k} className="flex gap-2 items-start">
                        <span className="text-blue-300 shrink-0">{k}:</span>
                        <ValueDisplay value={v} />
                    </div>
                ))}
            </div>
        )
    }

    return <span className="text-gray-300">{String(value)}</span>
}

const STATUS_ROW: Record<string, string> = {
    running: 'text-blue-400',
    paused: 'text-yellow-400',
    completed: 'text-green-400',
    failed: 'text-red-400',
    pending: 'text-gray-400',
}

interface Props {
    runState: RunState | null
}

export function StateViewer({ runState }: Props) {
    if (!runState) {
        return (
            <div className="flex items-center justify-center h-full text-gray-500 text-sm">
                Run a workflow to see state here.
            </div>
        )
    }

    const metaData: Record<string, unknown> = {
        status: runState.status,
        active_pointers: runState.active_pointers,
    }
    const stateData = runState.state as Record<string, unknown>

    const metaChanged = useChangedKeys(metaData)
    const stateChanged = useChangedKeys(stateData)

    return (
        <div className="h-full overflow-auto p-3 font-mono text-xs space-y-1">
            {runState.error && (
                <div className="bg-red-900/80 border border-red-500 text-red-100 p-3 rounded-lg mb-3 font-sans">
                    <div className="font-semibold text-sm mb-1">
                        Error in <span className="font-mono text-red-300">{runState.error.node}</span>
                    </div>
                    <p className="text-sm mb-1">{runState.error.message}</p>
                    <span className="text-xs text-red-400 font-mono">
                        {runState.error.file}:{runState.error.line}
                    </span>
                </div>
            )}

            {/* Meta section */}
            <div className="text-gray-600 uppercase tracking-widest text-[10px] mb-1 font-sans">meta</div>
            {Object.entries(metaData).map(([key, value]) => (
                <div
                    key={key}
                    className={`flex gap-2 items-start px-2 py-0.5 rounded transition-colors duration-100 ${
                        metaChanged.has(key) ? 'bg-blue-500/20' : ''
                    }`}
                >
                    <span className="text-gray-500 shrink-0 w-32 truncate">{key}</span>
                    <span className={key === 'status' ? (STATUS_ROW[runState.status] ?? 'text-gray-300') : ''}>
                        <ValueDisplay value={value} />
                    </span>
                </div>
            ))}

            {/* State section */}
            <div className="text-gray-600 uppercase tracking-widest text-[10px] mt-3 mb-1 font-sans">state</div>
            {Object.keys(stateData).length === 0 ? (
                <div className="text-gray-600 px-2">empty</div>
            ) : (
                Object.entries(stateData).map(([key, value]) => (
                    <div
                        key={key}
                        className={`flex gap-2 items-start px-2 py-0.5 rounded transition-colors duration-100 ${
                            stateChanged.has(key) ? 'bg-blue-500/20' : ''
                        }`}
                    >
                        <span className="text-gray-500 shrink-0 w-32 truncate">{key}</span>
                        <ValueDisplay value={value} />
                    </div>
                ))
            )}
        </div>
    )
}
