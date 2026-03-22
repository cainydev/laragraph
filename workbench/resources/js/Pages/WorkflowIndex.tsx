import { Link } from '@inertiajs/react'
import type { WorkflowEdgeDef } from '../components/WorkflowCanvas'

interface WorkflowMeta {
    name: string
    label: string
    description: string
    nodes: string[]
    edges: WorkflowEdgeDef[]
}

interface Props {
    workflows: WorkflowMeta[]
}

export default function WorkflowIndex({ workflows }: Props) {
    return (
        <div className="min-h-screen bg-gray-950 text-gray-100">
            <header className="px-8 py-6 border-b border-gray-800">
                <h1 className="text-2xl font-bold tracking-tight text-white">LaraGraph Workbench</h1>
                <p className="text-sm text-gray-400 mt-1">Select a workflow to inspect and run</p>
            </header>

            <main className="px-8 py-8">
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                    {workflows.map((wf) => (
                        <Link
                            key={wf.name}
                            href={`/workflow/${wf.name}`}
                            className="block bg-gray-900 border border-gray-800 rounded-xl p-5 hover:border-gray-600 hover:bg-gray-800 transition-colors group"
                        >
                            <h2 className="text-base font-semibold text-white group-hover:text-blue-400 transition-colors">
                                {wf.label}
                            </h2>
                            <p className="text-sm text-gray-400 mt-2 leading-snug">{wf.description}</p>
                            <div className="mt-4 flex flex-wrap gap-1.5">
                                {wf.nodes.map((node) => (
                                    <span
                                        key={node}
                                        className="text-xs font-mono bg-gray-800 border border-gray-700 text-gray-300 px-2 py-0.5 rounded"
                                    >
                                        {node}
                                    </span>
                                ))}
                            </div>
                        </Link>
                    ))}
                </div>
            </main>
        </div>
    )
}
