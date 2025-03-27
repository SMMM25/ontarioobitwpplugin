
import { Badge } from "@/components/ui/badge";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { CheckCircle, AlertCircle } from "lucide-react";

interface SourcesStatusPanelProps {
  scrapedSources: string[];
  connectionErrors: string[];
  isLoading: boolean;
}

const SourcesStatusPanel = ({
  scrapedSources,
  connectionErrors,
  isLoading
}: SourcesStatusPanelProps) => {
  return (
    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
      <Card className="border-border/50">
        <CardHeader className="py-3 px-4">
          <CardTitle className="text-sm font-medium">Successful Connections</CardTitle>
        </CardHeader>
        <CardContent className="py-3 px-4">
          {scrapedSources.length > 0 ? (
            <div className="flex flex-wrap gap-2">
              {scrapedSources.map((source) => (
                <Badge key={source} variant="outline" className="bg-green-500/10 text-green-700 border-green-200">
                  <CheckCircle className="h-3 w-3 mr-1" />
                  {source}
                </Badge>
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">
              {isLoading ? "Testing connections..." : "No successful connections yet"}
            </p>
          )}
        </CardContent>
      </Card>
      
      <Card className="border-border/50">
        <CardHeader className="py-3 px-4">
          <CardTitle className="text-sm font-medium">Failed Connections</CardTitle>
        </CardHeader>
        <CardContent className="py-3 px-4">
          {connectionErrors.length > 0 ? (
            <div className="flex flex-wrap gap-2">
              {connectionErrors.map((source) => (
                <Badge key={source} variant="outline" className="bg-red-500/10 text-red-700 border-red-200">
                  <AlertCircle className="h-3 w-3 mr-1" />
                  {source}
                </Badge>
              ))}
            </div>
          ) : (
            <p className="text-sm text-muted-foreground">
              {isLoading ? "Testing connections..." : "No connection errors yet"}
            </p>
          )}
        </CardContent>
      </Card>
    </div>
  );
};

export default SourcesStatusPanel;
