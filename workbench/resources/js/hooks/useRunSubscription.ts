import { useEchoPublic } from '@laravel/echo-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import './useEcho'

export type NodeStatus = 'idle' | 'executing' | 'completed' | 'failed'

export interface RunError {
    node: string
    message: string
    file: string
    line: number
}

export interface RunState {
    id: number
    key: string
    status: string
    state: Record<string, unknown>
    active_pointers: string[]
    error: RunError | null
}

interface UseRunSubscriptionResult {
    runState: RunState
    nodeStatuses: Record<string, NodeStatus>
    pause: () => Promise<void>
    abort: () => Promise<void>
    resume: (payload?: Record<string, unknown>) => Promise<void>
}

type NodeEvent = { nodeName: string }
type RunEvent = { runId: number }

const csrf = (): string =>
    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? ''

export function useRunSubscription(runId: number, initial: RunState): UseRunSubscriptionResult {
    const [runState, setRunState] = useState<RunState>(initial)
    const [nodeStatuses, setNodeStatuses] = useState<Record<string, NodeStatus>>({})
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null)
    const runStateRef = useRef(runState)
    runStateRef.current = runState

    const fetchRunState = useCallback(async () => {
        const res = await fetch(`/api/runs/${runId}`)
        if (res.ok) {
            const data = (await res.json()) as RunState
            setRunState(data)
            if (data.status === 'completed' || data.status === 'failed') {
                if (pollRef.current) {
                    clearInterval(pollRef.current)
                    pollRef.current = null
                }
            }
        }
    }, [runId])

    const channel = `workflow.${runId}`

    useEchoPublic<NodeEvent>(channel, '.NodeExecuting', (e) => {
        setNodeStatuses((prev) => ({ ...prev, [e.nodeName]: 'executing' }))
    })

    useEchoPublic<NodeEvent>(channel, '.NodeCompleted', (e) => {
        setNodeStatuses((prev) => ({ ...prev, [e.nodeName]: 'completed' }))
        void fetchRunState()
    })

    useEchoPublic<NodeEvent>(channel, '.NodeFailed', (e) => {
        setNodeStatuses((prev) => ({ ...prev, [e.nodeName]: 'failed' }))
        void fetchRunState()
    })

    useEchoPublic<RunEvent>(channel, '.WorkflowCompleted', () => {
        void fetchRunState()
    })

    useEchoPublic<RunEvent>(channel, '.WorkflowFailed', () => {
        void fetchRunState()
    })

    // Polling fallback in case broadcasting is unavailable
    useEffect(() => {
        const isTerminal = runState.status === 'completed' || runState.status === 'failed'
        if (isTerminal) return

        pollRef.current = setInterval(() => void fetchRunState(), 3000)
        return () => {
            if (pollRef.current) {
                clearInterval(pollRef.current)
                pollRef.current = null
            }
        }
    }, [runState.status, fetchRunState])

    const postAction = useCallback(
        async (action: 'pause' | 'abort' | 'resume', body?: Record<string, unknown>) => {
            await fetch(`/api/runs/${runId}/${action}`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf(),
                    Accept: 'application/json',
                    ...(body ? { 'Content-Type': 'application/json' } : {}),
                },
                body: body ? JSON.stringify(body) : undefined,
            })
            await fetchRunState()
        },
        [runId, fetchRunState],
    )

    const pause = useCallback(() => postAction('pause'), [postAction])
    const abort = useCallback(() => postAction('abort'), [postAction])
    const resume = useCallback((payload?: Record<string, unknown>) => postAction('resume', payload), [postAction])

    return { runState, nodeStatuses, pause, abort, resume }
}
